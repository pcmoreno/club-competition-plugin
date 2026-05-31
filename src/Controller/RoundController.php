<?php

declare(strict_types=1);

namespace SCS\Controller;

use SCS\Entity\Enum\AttendanceStatus;
use SCS\Entity\Enum\ByeType;
use SCS\Entity\Enum\GameResult;
use SCS\Entity\Enum\RoundStatus;
use SCS\Exception\NotFoundException;
use SCS\Repository\AttendanceRepository;
use SCS\Repository\GameRepository;
use SCS\Repository\PlayerRepository;
use SCS\Repository\RoundRepository;
use SCS\Repository\SeasonPlayerRepository;
use SCS\Repository\SeasonRepository;
use SCS\Request\CreateRoundRequest;
use SCS\Request\SaveAttendanceRequest;
use SCS\Request\UpdateGameResultRequest;
use SCS\Request\UpdateRoundStatusRequest;
use SCS\Services\SerializerService;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class RoundController extends RestController
{
    public function __construct(
        ValidatorInterface $validator,
        private readonly RoundRepository $roundRepository,
        private readonly GameRepository $gameRepository,
        private readonly AttendanceRepository $attendanceRepository,
        private readonly SeasonRepository $seasonRepository,
        private readonly SeasonPlayerRepository $seasonPlayerRepository,
        private readonly PlayerRepository $playerRepository,
        private readonly SerializerService $serializer,
    ) {
        parent::__construct($validator);
    }

    public function index(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $season = $this->seasonRepository->findById((int)$request->get_param('season_id'));
            if ($season === null) {
                throw new NotFoundException('Season not found.');
            }

            $rounds = $this->roundRepository->findBySeason($season->id);

            return $this->ok(array_map($this->serializer->serialize(...), $rounds));
        });
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $round = $this->roundRepository->findById((int)$request->get_param('id'));
            if ($round === null) {
                throw new NotFoundException('Round not found.');
            }

            $games      = $this->gameRepository->findByRound($round->id);
            $attendance = $this->attendanceRepository->findByRound($round->id);

            // Resolve season_player ids to display info (name + category + elo)
            // server-side, so a single request renders the whole round without
            // the client joining games → season_players → players.
            $display = $this->playerDisplayMap($round->season_id);

            $games = array_map(fn ($g) => [
                'id'     => $g->id,
                'board'  => $g->board,
                'result' => $g->result?->value,
                'white'  => $display[$g->white_season_player_id] ?? null,
                'black'  => $display[$g->black_season_player_id] ?? null,
            ], $games);

            // Byes (the odd-player-out and any sit-outs) are attendance rows
            // carrying a bye_type, not games.
            $byes = array_values(array_map(
                fn ($a) => [
                    'season_player_id' => $a->season_player_id,
                    'name'             => $display[$a->season_player_id]['name'] ?? null,
                    'category'         => $display[$a->season_player_id]['category'] ?? null,
                    'bye_type'         => $a->bye_type?->value,
                ],
                array_filter($attendance, fn ($a) => $a->bye_type !== null)
            ));

            return $this->ok([
                'round' => $this->serializer->serialize($round),
                'games' => $games,
                'byes'  => $byes,
            ]);
        });
    }

    /**
     * Builds a season_player_id → display map (name, category, elo) for a
     * season. Shared by the round payload's games and byes; a player's name
     * comes from the roster, their category/elo from the season enrollment.
     *
     * @return array<int, array{season_player_id: int, name: ?string, category: ?string, elo: int}>
     */
    private function playerDisplayMap(int $season_id): array
    {
        $names = [];
        foreach ($this->playerRepository->findAll() as $player) {
            $names[$player->id] = $player->name;
        }

        $map = [];
        foreach ($this->seasonPlayerRepository->findBySeason($season_id) as $sp) {
            $map[$sp->id] = [
                'season_player_id' => $sp->id,
                'player_id'        => $sp->player_id,
                'name'             => $names[$sp->player_id] ?? null,
                'category'         => $sp->category,
                'elo'              => $sp->elo_rating,
            ];
        }

        return $map;
    }

    public function store(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $season = $this->seasonRepository->findById((int)$request->get_param('season_id'));
            if ($season === null) {
                throw new NotFoundException('Season not found.');
            }

            $input = CreateRoundRequest::fromRequest($request);
            $this->validate($input);

            $round = $this->roundRepository->createNextForSeason(
                season_id: $season->id,
                date:      $input->date,
            );

            return $this->created($this->serializer->serialize($round, SerializerService::GROUP_ADMIN));
        });
    }

    public function updateStatus(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $round = $this->roundRepository->findById((int)$request->get_param('id'));
            if ($round === null) {
                throw new NotFoundException('Round not found.');
            }

            $input = UpdateRoundStatusRequest::fromRequest($request);
            $this->validate($input);

            $this->roundRepository->updateStatus($round->id, RoundStatus::from($input->status));

            return $this->ok($this->serializer->serialize($this->roundRepository->findById($round->id), SerializerService::GROUP_ADMIN));
        });
    }

    public function saveAttendance(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $round = $this->roundRepository->findById((int)$request->get_param('id'));
            if ($round === null) {
                throw new NotFoundException('Round not found.');
            }

            $input = SaveAttendanceRequest::fromRequest($request);
            $this->validate($input);

            $parsed = [];
            foreach ($input->attendance as $entry) {
                $parsed[] = [
                    'season_player_id' => (int)$entry['season_player_id'],
                    'status'           => AttendanceStatus::from($entry['status']),
                    'bye_type'         => isset($entry['bye_type']) ? ByeType::from($entry['bye_type']) : null,
                ];
            }

            $this->attendanceRepository->saveMany($round->id, $parsed);

            $attendance = $this->attendanceRepository->findByRound($round->id);

            return $this->ok(array_map($this->serializer->serialize(...), $attendance));
        });
    }

    public function updateGameResult(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $game = $this->gameRepository->findById((int)$request->get_param('id'));
            if ($game === null) {
                throw new NotFoundException('Game not found.');
            }

            $input = UpdateGameResultRequest::fromRequest($request);
            $this->validate($input);

            $result = $input->result !== null ? GameResult::from($input->result) : null;

            $this->gameRepository->updateResult($game->id, $result);

            return $this->ok($this->serializer->serialize($this->gameRepository->findById($game->id)));
        });
    }
}
