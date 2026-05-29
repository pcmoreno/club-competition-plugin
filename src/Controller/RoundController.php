<?php

declare(strict_types=1);

namespace SCS\Controller;

use SCS\Entity\Enum\AttendanceStatus;
use SCS\Entity\Enum\ByeType;
use SCS\Entity\Enum\GameResult;
use SCS\Entity\Enum\RoundStatus;
use SCS\Http\StatusCode;
use SCS\Repository\AttendanceRepository;
use SCS\Repository\GameRepository;
use SCS\Repository\RoundRepository;
use SCS\Repository\SeasonRepository;
use SCS\Services\SerializerService;

class RoundController
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
        $season = $this->seasonRepository->findById((int)$request->get_param('season_id'));
        if ($season === null) {
            return new \WP_REST_Response(['error' => 'Season not found.'], StatusCode::NOT_FOUND);
        }

        $rounds = $this->roundRepository->findBySeason($season->id);

        return new \WP_REST_Response(array_map($this->serializer->serialize(...), $rounds), StatusCode::OK);
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response
    {
        $round = $this->roundRepository->findById((int)$request->get_param('id'));
        if ($round === null) {
            return new \WP_REST_Response(['error' => 'Round not found.'], StatusCode::NOT_FOUND);
        }

        $games      = $this->gameRepository->findByRound($round->id);
        $attendance = $this->attendanceRepository->findByRound($round->id);

        return new \WP_REST_Response([
            'round'      => $this->serializer->serialize($round),
            'games'      => array_map($this->serializer->serialize(...), $games),
            'attendance' => array_map($this->serializer->serialize(...), $attendance),
        ], StatusCode::OK);
    }

    public function store(\WP_REST_Request $request): \WP_REST_Response
    {
        $season = $this->seasonRepository->findById((int)$request->get_param('season_id'));
        if ($season === null) {
            return new \WP_REST_Response(['error' => 'Season not found.'], StatusCode::NOT_FOUND);
        }

        $existingRounds = $this->roundRepository->findBySeason($season->id);
        $roundNumber    = count($existingRounds) + 1;

        $round = $this->roundRepository->create(
            season_id:    $season->id,
            round_number: $roundNumber,
            date:         $request->get_param('date') !== null ? (string)$request->get_param('date') : null,
        );

        return new \WP_REST_Response($this->serializer->serialize($round), StatusCode::CREATED);
    }

    public function updateStatus(\WP_REST_Request $request): \WP_REST_Response
    {
        $round = $this->roundRepository->findById((int)$request->get_param('id'));
        if ($round === null) {
            return new \WP_REST_Response(['error' => 'Round not found.'], StatusCode::NOT_FOUND);
        }

        $status = RoundStatus::tryFrom((string)$request->get_param('status'));
        if ($status === null) {
            return new \WP_REST_Response(['error' => 'Invalid status.'], StatusCode::BAD_REQUEST);
        }

        $this->roundRepository->updateStatus($round->id, $status);

        return new \WP_REST_Response($this->serializer->serialize($this->roundRepository->findById($round->id)), StatusCode::OK);
    }

    public function saveAttendance(\WP_REST_Request $request): \WP_REST_Response
    {
        $round = $this->roundRepository->findById((int)$request->get_param('id'));
        if ($round === null) {
            return new \WP_REST_Response(['error' => 'Round not found.'], StatusCode::NOT_FOUND);
        }

        $entries = $request->get_param('attendance');
        if (!is_array($entries)) {
            return new \WP_REST_Response(['error' => 'attendance must be an array.'], StatusCode::BAD_REQUEST);
        }

        foreach ($entries as $entry) {
            $status  = AttendanceStatus::tryFrom((string)($entry['status'] ?? ''));
            $byeType = isset($entry['bye_type']) ? ByeType::tryFrom((string)$entry['bye_type']) : null;

            if ($status === null) {
                return new \WP_REST_Response(['error' => 'Invalid attendance status.'], StatusCode::BAD_REQUEST);
            }

            $this->attendanceRepository->save(
                round_id:         $round->id,
                season_player_id: (int)$entry['season_player_id'],
                status:           $status,
                bye_type:         $byeType,
            );
        }

        $attendance = $this->attendanceRepository->findByRound($round->id);

        return new \WP_REST_Response(array_map($this->serializer->serialize(...), $attendance), StatusCode::OK);
    }

    public function updateGameResult(\WP_REST_Request $request): \WP_REST_Response
    {
        $game = $this->gameRepository->findById((int)$request->get_param('id'));
        if ($game === null) {
            return new \WP_REST_Response(['error' => 'Game not found.'], StatusCode::NOT_FOUND);
        }

        $resultParam = $request->get_param('result');
        $result      = $resultParam !== null ? GameResult::tryFrom((string)$resultParam) : null;

        if ($resultParam !== null && $result === null) {
            return new \WP_REST_Response(['error' => 'Invalid result value.'], StatusCode::BAD_REQUEST);
        }

        $this->gameRepository->updateResult($game->id, $result);

        return new \WP_REST_Response($this->serializer->serialize($this->gameRepository->findById($game->id)), StatusCode::OK);
    }
}
