<?php

declare(strict_types=1);

namespace SCS\Controller;

use SCS\Entity\Enum\PairingSystem;
use SCS\Exception\ConflictException;
use SCS\Exception\NotFoundException;
use SCS\Exception\ValidationException;
use SCS\Repository\PlayerRepository;
use SCS\Repository\SeasonPlayerRepository;
use SCS\Repository\SeasonRepository;
use SCS\Repository\StandingsSnapshotRepository;
use SCS\Request\CreateSeasonRequest;
use SCS\Request\EnrollPlayerRequest;
use SCS\Request\UpdateSeasonRequest;
use SCS\Services\PlayerDisplayService;
use SCS\Services\SerializerService;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class SeasonController extends RestController
{
    public function __construct(
        ValidatorInterface $validator,
        private readonly SeasonRepository $seasonRepository,
        private readonly SeasonPlayerRepository $seasonPlayerRepository,
        private readonly PlayerRepository $playerRepository,
        private readonly PlayerDisplayService $playerDisplay,
        private readonly StandingsSnapshotRepository $standingsSnapshotRepository,
        private readonly SerializerService $serializer,
    ) {
        parent::__construct($validator);
    }

    public function index(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () {
            $seasons = $this->seasonRepository->findAll();

            return $this->ok(array_map($this->serializer->serialize(...), $seasons));
        });
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $season = $this->seasonRepository->findById((int)$request->get_param('id'));
            if ($season === null) {
                throw new NotFoundException('Season not found.');
            }

            // Display-ready enrolled players (name + category + elo), resolved
            // server-side so the roster renders without a separate fetch.
            $players = array_values($this->playerDisplay->mapForSeason($season->id));

            return $this->ok([
                'season'  => $this->serializer->serialize($season),
                'players' => $players,
            ]);
        });
    }

    public function standings(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $season = $this->seasonRepository->findById((int)$request->get_param('id'));
            if ($season === null) {
                throw new NotFoundException('Season not found.');
            }

            // Standings as of a specific round (?round=ID) — used by the round
            // history "standings after round N" panel — or, by default, the
            // latest completed round's snapshot. Each row is enriched with the
            // player's display info.
            $roundParam = $request->get_param('round');
            $snapshots  = $roundParam !== null
                ? $this->standingsSnapshotRepository->findByRound((int)$roundParam)
                : $this->standingsSnapshotRepository->findLatestForSeason($season->id);
            $display = $this->playerDisplay->mapForSeason($season->id);

            // Movers: each player's rank change vs the previous snapshot-bearing
            // round. rank_delta > 0 means moved up (rank number got smaller);
            // null means no prior snapshot (first round, or a new entrant).
            $previousRank = [];
            if ($snapshots !== []) {
                $previousRoundId = $this->standingsSnapshotRepository
                    ->findPreviousRoundId($season->id, $snapshots[0]->round_id);
                if ($previousRoundId !== null) {
                    foreach ($this->standingsSnapshotRepository->findByRound($previousRoundId) as $p) {
                        $previousRank[$p->season_player_id] = $p->rank;
                    }
                }
            }

            $standings = array_map(function ($s) use ($display, $previousRank) {
                $d = $display[$s->season_player_id] ?? null;

                return [
                    'rank'             => $s->rank,
                    'season_player_id' => $s->season_player_id,
                    'player_id'        => $d['player_id'] ?? null,
                    'name'             => $d['name'] ?? null,
                    'category'         => $d['category'] ?? null,
                    'elo'              => $d['elo'] ?? null,
                    'keizer_score'     => $s->keizer_score,
                    'classical_points' => $s->classical_points,
                    'wins'             => $s->wins,
                    'draws'            => $s->draws,
                    'losses'           => $s->losses,
                    'games'            => $s->games,
                    'byes'             => $s->byes,
                    'color_balance'    => $s->color_balance,
                    'tpr'              => $s->tpr,
                    'rank_delta'       => isset($previousRank[$s->season_player_id])
                        ? $previousRank[$s->season_player_id] - $s->rank
                        : null,
                ];
            }, $snapshots);

            return $this->ok([
                'season'    => $this->serializer->serialize($season),
                'standings' => $standings,
            ]);
        });
    }

    public function store(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $input = CreateSeasonRequest::fromRequest($request);
            $this->validate($input);

            $season = $this->seasonRepository->create(
                name:           $input->name,
                location:       $input->location,
                start_date:     $input->start_date,
                end_date:       $input->end_date,
                pairing_system: PairingSystem::from($input->pairing_system),
                categories:     $input->categories,
            );

            return $this->created($this->serializer->serialize($season, SerializerService::GROUP_ADMIN));
        });
    }

    public function update(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $season = $this->seasonRepository->findById((int)$request->get_param('id'));
            if ($season === null) {
                throw new NotFoundException('Season not found.');
            }

            $input = UpdateSeasonRequest::fromRequest($request);
            $this->validate($input);

            $data = $input->toUpdateData();
            if (empty($data)) {
                throw new ValidationException(['fields' => 'No fields to update.']);
            }

            $this->seasonRepository->update($season->id, $data);

            return $this->ok($this->serializer->serialize($this->seasonRepository->findById($season->id), SerializerService::GROUP_ADMIN));
        });
    }

    public function enrollPlayer(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $season = $this->seasonRepository->findById((int)$request->get_param('id'));
            if ($season === null) {
                throw new NotFoundException('Season not found.');
            }

            $input = EnrollPlayerRequest::fromRequest($request);
            $this->validate($input);

            if ($season->categories === []) {
                if ($input->category !== null) {
                    throw new ValidationException([
                        'category' => 'This season has no categories; leave the category empty.',
                    ]);
                }
            } elseif ($input->category === null) {
                throw new ValidationException([
                    'category' => sprintf('Category is required. Choose one of: %s.', implode(', ', $season->categories)),
                ]);
            } elseif (!in_array($input->category, $season->categories, true)) {
                throw new ValidationException([
                    'category' => sprintf('Category must be one of: %s.', implode(', ', $season->categories)),
                ]);
            }

            $player = $this->playerRepository->findById($input->player_id);
            if ($player === null) {
                throw new NotFoundException('Player not found.');
            }

            $existing = $this->seasonPlayerRepository->findBySeasonAndPlayer($season->id, $input->player_id);
            if ($existing !== null) {
                throw new ConflictException('Player is already enrolled in this season.');
            }

            $eloRating = $input->elo_rating ?? $player->knsb_elo ?? 0;

            $seasonPlayer = $this->seasonPlayerRepository->create($season->id, $input->player_id, $input->category, $eloRating);

            return $this->created($this->serializer->serialize($seasonPlayer));
        });
    }

    public function removePlayer(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $season = $this->seasonRepository->findById((int)$request->get_param('id'));
            if ($season === null) {
                throw new NotFoundException('Season not found.');
            }

            $seasonPlayer = $this->seasonPlayerRepository->findBySeasonAndPlayer(
                $season->id,
                (int)$request->get_param('player_id')
            );

            if ($seasonPlayer === null) {
                throw new NotFoundException('Player is not enrolled in this season.');
            }

            $this->seasonPlayerRepository->delete($seasonPlayer->id);

            return $this->noContent();
        });
    }
}
