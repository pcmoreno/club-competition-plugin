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
use SCS\Repository\RoundRepository;
use SCS\Repository\SeasonRepository;
use SCS\Request\CreateRoundRequest;
use SCS\Request\SaveAttendanceRequest;
use SCS\Request\UpdateGameResultRequest;
use SCS\Request\UpdateRoundStatusRequest;
use SCS\Services\SerializerService;

class RoundController extends RestController
{
    public function __construct(
        private readonly RoundRepository $roundRepository,
        private readonly GameRepository $gameRepository,
        private readonly AttendanceRepository $attendanceRepository,
        private readonly SeasonRepository $seasonRepository,
        private readonly SerializerService $serializer,
    ) {
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

            return $this->ok([
                'round'      => $this->serializer->serialize($round),
                'games'      => array_map($this->serializer->serialize(...), $games),
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

            $round = $this->roundRepository->createNextForSeason(
                season_id: $season->id,
                date:      $input->date,
            );

            return $this->created($this->serializer->serialize($round));
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

            return $this->ok($this->serializer->serialize($this->roundRepository->findById($round->id)));
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
