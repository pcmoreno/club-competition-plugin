<?php

declare(strict_types=1);

namespace SCS\Controller;

use SCS\Entity\Enum\AttendanceStatus;
use SCS\Entity\Enum\ByeType;
use SCS\Entity\Enum\GameResult;
use SCS\Entity\Enum\RoundStatus;
use SCS\Exception\NotFoundException;
use SCS\Exception\ValidationException;
use SCS\Repository\AttendanceRepository;
use SCS\Repository\GameRepository;
use SCS\Repository\RoundRepository;
use SCS\Repository\SeasonRepository;
use SCS\Request\CreatePairingRequest;
use SCS\Request\CreateRoundRequest;
use SCS\Request\SaveAttendanceRequest;
use SCS\Request\UpdateGameResultRequest;
use SCS\Request\UpdatePairingRequest;
use SCS\Request\UpdateRoundRequest;
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

    // Re-read after a write. Throws rather than handing a null to the
    // serializer, which would surface as a 500 on a request that succeeded.
    private function requireRound(int $id): \SCS\Entity\Round
    {
        $round = $this->roundRepository->findById($id);
        if ($round === null) {
            throw new NotFoundException('Round not found.');
        }

        return $round;
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
                'id'           => $g->id,
                'board'        => $g->board,
                'result'       => $g->result?->value,
                'time_control' => $g->time_control->value,
                'white'        => $display[$g->white_season_player_id] ?? null,
                'black'        => $display[$g->black_season_player_id] ?? null,
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
                'round'      => $this->serializer->serialize($round),
                'games'      => $games,
                'byes'       => $byes,
                'attendance' => array_map($this->serializer->serialize(...), $attendance),
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

            $round = $this->roundService->createRound($season, $input->date);

            return $this->created($this->serializer->serialize($round, SerializerService::GROUP_ADMIN));
        });
    }

    /**
     * Build the whole fixture at once. Only for a full-schedule system
     * (round-robin); the service refuses anything else, and refuses to rebuild
     * once a round has left draft.
     */
    public function generate(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $season = $this->seasonRepository->findById((int)$request->get_param('season_id'));
            if ($season === null) {
                throw new NotFoundException('Season not found.');
            }

            $rounds = $this->roundService->generateSchedule($season);

            return $this->created([
                'rounds' => array_map(
                    fn ($round) => $this->serializer->serialize($round, SerializerService::GROUP_ADMIN),
                    $rounds
                ),
            ]);
        });
    }

    /**
     * Build this round's boards from the standings. Only for a per-round system
     * (Keizer); the service refuses a full-schedule one, and refuses a round
     * that already has pairings.
     */
    public function generatePairings(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $round = $this->roundRepository->findById((int)$request->get_param('id'));
            if ($round === null) {
                throw new NotFoundException('Round not found.');
            }

            $games = $this->roundService->pairRound($round);

            return $this->created([
                'games' => array_map(
                    fn ($game) => $this->serializer->serialize($game, SerializerService::GROUP_ADMIN),
                    $games
                ),
            ]);
        });
    }

    /**
     * Set or clear the round's date. Not guarded on round status: the date is
     * when the evening was played, not competition data, so correcting it after
     * the fact is a legitimate admin fix.
     */
    public function update(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $round = $this->roundRepository->findById((int)$request->get_param('id'));
            if ($round === null) {
                throw new NotFoundException('Round not found.');
            }

            $this->roundService->assertSeasonOpen($round->season_id);

            $input = UpdateRoundRequest::fromRequest($request);
            $this->validate($input);

            if (!$input->dateProvided) {
                throw new ValidationException(['fields' => 'No fields to update.']);
            }

            $this->roundRepository->update($round->id, ['date' => $input->date]);

            return $this->ok($this->serializer->serialize($this->requireRound($round->id), SerializerService::GROUP_ADMIN));
        });
    }

    public function updateStatus(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $round = $this->roundRepository->findById((int)$request->get_param('id'));
            if ($round === null) {
                throw new NotFoundException('Round not found.');
            }

            // The plain transitions below write through the repository; the service guards its own.
            $this->roundService->assertSeasonOpen($round->season_id);

            $input = UpdateRoundStatusRequest::fromRequest($request);
            $this->validate($input);

            $newStatus = RoundStatus::from($input->status);

            // Only completing a round can close the tournament, and every other
            // branch below would drop the flag without saying so — too quiet for
            // the one write with no undo. Opting out stays harmless.
            if ($input->complete_season && $newStatus !== RoundStatus::Complete) {
                throw new ValidationException([
                    'complete_season' => 'A tournament is completed along with its final round, so this needs status "complete".',
                ]);
            }

            // Reopening runs through the service so the "only a completed round"
            // guard applies; it deliberately keeps the existing snapshot.
            if ($round->status === RoundStatus::Complete && $newStatus === RoundStatus::Finalised) {
                $this->roundService->reopenRound($round);

                return $this->ok($this->serializer->serialize($this->requireRound($round->id), SerializerService::GROUP_ADMIN));
            }

            // Completing a round freezes its standings snapshot, and refreshes
            // every later completed round's — they accumulate this one's games.
            // The service owns that status write so it shares a transaction
            // with the scoring that can refuse it.
            if ($newStatus === RoundStatus::Complete) {
                $this->roundService->completeRound($round, $input->complete_season);
            } else {
                $this->roundRepository->updateStatus($round->id, $newStatus);
            }

            return $this->ok($this->serializer->serialize($this->requireRound($round->id), SerializerService::GROUP_ADMIN));
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

            $this->roundService->saveAttendance($round->id, $parsed);

            $attendance = $this->attendanceRepository->findByRound($round->id);

            return $this->ok(array_map($this->serializer->serialize(...), $attendance));
        });
    }

    public function updateGameResult(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $input = UpdateGameResultRequest::fromRequest($request);
            $this->validate($input);

            $result = $input->result !== null ? GameResult::from($input->result) : null;

            // The round status guard lives in the service, alongside the one
            // for pairing edits.
            $game = $this->roundService->updateGameResult((int)$request->get_param('id'), $result);

            return $this->ok($this->serializer->serialize($game));
        });
    }

    public function createGame(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $input = CreatePairingRequest::fromRequest($request);
            $this->validate($input);

            $game = $this->roundService->addPairing(
                (int)$request->get_param('id'),
                $input->white_season_player_id,
                $input->black_season_player_id,
                $input->board,
            );

            return $this->created($this->serializer->serialize($game));
        });
    }

    public function updateGame(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $input = UpdatePairingRequest::fromRequest($request);
            $this->validate($input);

            $game = $this->roundService->updatePairing(
                (int)$request->get_param('id'),
                $input->white_season_player_id,
                $input->black_season_player_id,
                $input->board,
            );

            return $this->ok($this->serializer->serialize($game));
        });
    }

    public function deleteGame(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $this->roundService->removePairing((int)$request->get_param('id'));

            return $this->ok(['deleted' => true]);
        });
    }
}
