<?php

declare(strict_types=1);

namespace SCS\Controller;

use SCS\Entity\Enum\AttendanceStatus;
use SCS\Entity\Enum\ByeType;
use SCS\Entity\Enum\GameResult;
use SCS\Entity\Enum\RoundStatus;
use SCS\Exception\ConflictException;
use SCS\Exception\NotFoundException;
use SCS\Exception\ValidationException;
use SCS\Repository\AttendanceRepository;
use SCS\Repository\GameRepository;
use SCS\Repository\RoundRepository;
use SCS\Repository\SeasonRepository;
use SCS\Request\CreatePairingRequest;
use SCS\Request\CreateRoundRequest;
use SCS\Request\SaveAttendanceRequest;
use SCS\Request\UpdatePairingRequest;
use SCS\Request\UpdateGameResultRequest;
use SCS\Request\UpdateRoundStatusRequest;
use SCS\Services\PlayerDisplayService;
use SCS\Services\RoundService;
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
        private readonly PlayerDisplayService $playerDisplay,
        private readonly SerializerService $serializer,
        private readonly RoundService $roundService,
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
            $display = $this->playerDisplay->mapForSeason($round->season_id);

            $games = array_map(fn ($g) => [
                'id'     => $g->id,
                'board'  => $g->board,
                'result' => $g->result?->value,
                'white'  => $display[$g->white_season_player_id] ?? null,
                'black'  => $display[$g->black_season_player_id] ?? null,
            ], $games);

            // The pairing sheet's "Bye" line lists only *pairing* byes — the
            // present-but-unpaired player(s). Absences (status absent, e.g. a
            // personal/club-duty no-show) are tracked in attendance but are not
            // byes and must not appear here.
            $byes = array_values(array_map(
                fn ($a) => [
                    'season_player_id' => $a->season_player_id,
                    'name'             => $display[$a->season_player_id]['name'] ?? null,
                    'category'         => $display[$a->season_player_id]['category'] ?? null,
                    'bye_type'         => $a->bye_type?->value,
                ],
                array_filter($attendance, fn ($a) => $a->bye_type === ByeType::PairingBye)
            ));

            return $this->ok([
                'round' => $this->serializer->serialize($round),
                'games' => $games,
                'byes'  => $byes,
            ]);
        });
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

            $newStatus = RoundStatus::from($input->status);
            $this->roundRepository->updateStatus($round->id, $newStatus);

            // Completing a round freezes its standings snapshot.
            if ($newStatus === RoundStatus::Complete) {
                $this->roundService->completeRound($round);
            }

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

    public function createGame(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $round = $this->assertRoundEditable($this->roundRepository->findById((int)$request->get_param('id')));

            $input = CreatePairingRequest::fromRequest($request);
            $this->validate($input);

            $display = $this->playerDisplay->mapForSeason($round->season_id);
            $this->assertPairingValid($round, $input->white_season_player_id, $input->black_season_player_id, $display, null);

            $board = $input->board ?? $this->nextBoard($round->id);
            $game  = $this->gameRepository->create($round->id, $input->white_season_player_id, $input->black_season_player_id, $board);

            return $this->created($this->serializer->serialize($game));
        });
    }

    public function updateGame(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $game = $this->gameRepository->findById((int)$request->get_param('id'));
            if ($game === null) {
                throw new NotFoundException('Game not found.');
            }
            $round = $this->assertRoundEditable($this->roundRepository->findById($game->round_id));

            $input = UpdatePairingRequest::fromRequest($request);
            $this->validate($input);

            $white = $input->white_season_player_id ?? $game->white_season_player_id;
            $black = $input->black_season_player_id ?? $game->black_season_player_id;

            $display = $this->playerDisplay->mapForSeason($round->season_id);
            $this->assertPairingValid($round, $white, $black, $display, $game->id);

            $data = [
                'white_season_player_id' => $white,
                'black_season_player_id' => $black,
            ];
            if ($input->board !== null) {
                $data['board'] = $input->board;
            }
            $this->gameRepository->update($game->id, $data);

            $updated = $this->gameRepository->findById($game->id);
            if ($updated === null) {
                throw new NotFoundException('Game not found.');
            }

            return $this->ok($this->serializer->serialize($updated));
        });
    }

    public function deleteGame(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $game = $this->gameRepository->findById((int)$request->get_param('id'));
            if ($game === null) {
                throw new NotFoundException('Game not found.');
            }
            $this->assertRoundEditable($this->roundRepository->findById($game->round_id));

            $this->gameRepository->delete($game->id);

            return $this->ok(['deleted' => true]);
        });
    }

    // Pairings are editable while draft/published; finalised and complete are locked.
    private function assertRoundEditable(?\SCS\Entity\Round $round): \SCS\Entity\Round
    {
        if ($round === null) {
            throw new NotFoundException('Round not found.');
        }
        if ($round->status === RoundStatus::Finalised || $round->status === RoundStatus::Complete) {
            throw new ConflictException('Pairings are locked once the round is finalised.');
        }

        return $round;
    }

    /** @param array<int,mixed> $display season_player_id => display info for the season roster */
    private function assertPairingValid(\SCS\Entity\Round $round, int $white, int $black, array $display, ?int $excludeGameId): void
    {
        if ($white === $black) {
            throw new ValidationException(['players' => 'A player cannot be paired against themselves.']);
        }
        if (!isset($display[$white]) || !isset($display[$black])) {
            throw new ValidationException(['players' => 'Both players must be enrolled in this season.']);
        }

        foreach ($this->gameRepository->findByRound($round->id) as $existing) {
            if ($existing->id === $excludeGameId) {
                continue;
            }
            $paired = [$existing->white_season_player_id, $existing->black_season_player_id];
            if (in_array($white, $paired, true) || in_array($black, $paired, true)) {
                throw new ConflictException('A player is already paired in this round.');
            }
        }
    }

    private function nextBoard(int $roundId): int
    {
        $max = 0;
        foreach ($this->gameRepository->findByRound($roundId) as $game) {
            $max = max($max, $game->board ?? 0);
        }

        return $max + 1;
    }
}
