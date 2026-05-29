<?php

declare(strict_types=1);

namespace SCS\Controller;

use SCS\Entity\Enum\PairingSystem;
use SCS\Entity\Enum\SeasonStatus;
use SCS\Http\StatusCode;
use SCS\Repository\PlayerRepository;
use SCS\Repository\SeasonPlayerRepository;
use SCS\Repository\SeasonRepository;
use SCS\Services\SerializerService;

class SeasonController
{
    public function __construct(
        private readonly SeasonRepository $seasonRepository,
        private readonly SeasonPlayerRepository $seasonPlayerRepository,
        private readonly PlayerRepository $playerRepository,
        private readonly SerializerService $serializer,
    ) {
    }

    public function index(\WP_REST_Request $request): \WP_REST_Response
    {
        $seasons = $this->seasonRepository->findAll();

        return new \WP_REST_Response(array_map($this->serializer->serialize(...), $seasons), StatusCode::OK);
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response
    {
        $season = $this->seasonRepository->findById((int)$request->get_param('id'));
        if ($season === null) {
            return new \WP_REST_Response(['error' => 'Season not found.'], StatusCode::NOT_FOUND);
        }

        $seasonPlayers = $this->seasonPlayerRepository->findBySeason($season->id);

        return new \WP_REST_Response([
            'season'  => $this->serializer->serialize($season),
            'players' => array_map($this->serializer->serialize(...), $seasonPlayers),
        ], StatusCode::OK);
    }

    public function store(\WP_REST_Request $request): \WP_REST_Response
    {
        $name = trim((string)$request->get_param('name'));
        if ($name === '') {
            return new \WP_REST_Response(['error' => 'Name is required.'], StatusCode::BAD_REQUEST);
        }

        $pairingSystemValue = $request->get_param('pairing_system') ?? PairingSystem::Keizer->value;
        $pairingSystem      = PairingSystem::tryFrom((string)$pairingSystemValue);
        if ($pairingSystem === null) {
            return new \WP_REST_Response(['error' => 'Invalid pairing system.'], StatusCode::BAD_REQUEST);
        }

        $season = $this->seasonRepository->create(
            name:           $name,
            location:       $request->get_param('location') !== null ? (string)$request->get_param('location') : null,
            start_date:     $request->get_param('start_date') !== null ? (string)$request->get_param('start_date') : null,
            end_date:       $request->get_param('end_date') !== null ? (string)$request->get_param('end_date') : null,
            pairing_system: $pairingSystem,
            categories:     (array)($request->get_param('categories') ?? []),
        );

        return new \WP_REST_Response($this->serializer->serialize($season), StatusCode::CREATED);
    }

    public function update(\WP_REST_Request $request): \WP_REST_Response
    {
        $season = $this->seasonRepository->findById((int)$request->get_param('id'));
        if ($season === null) {
            return new \WP_REST_Response(['error' => 'Season not found.'], StatusCode::NOT_FOUND);
        }

        $data = array_filter([
            'name'       => $request->get_param('name') !== null ? trim((string)$request->get_param('name')) : null,
            'location'   => $request->get_param('location'),
            'start_date' => $request->get_param('start_date'),
            'end_date'   => $request->get_param('end_date'),
            'categories' => $request->get_param('categories') !== null ? json_encode($request->get_param('categories')) : null,
        ], fn ($v) => $v !== null);

        if ($request->get_param('pairing_system') !== null) {
            $ps = PairingSystem::tryFrom((string)$request->get_param('pairing_system'));
            if ($ps === null) {
                return new \WP_REST_Response(['error' => 'Invalid pairing system.'], StatusCode::BAD_REQUEST);
            }
            $data['pairing_system'] = $ps->value;
        }

        if ($request->get_param('status') !== null) {
            $status = SeasonStatus::tryFrom((string)$request->get_param('status'));
            if ($status === null) {
                return new \WP_REST_Response(['error' => 'Invalid status.'], StatusCode::BAD_REQUEST);
            }
            $data['status'] = $status->value;
        }

        if (empty($data)) {
            return new \WP_REST_Response(['error' => 'No fields to update.'], StatusCode::BAD_REQUEST);
        }

        $this->seasonRepository->update($season->id, $data);

        return new \WP_REST_Response($this->serializer->serialize($this->seasonRepository->findById($season->id)), StatusCode::OK);
    }

    public function enrollPlayer(\WP_REST_Request $request): \WP_REST_Response
    {
        $season = $this->seasonRepository->findById((int)$request->get_param('id'));
        if ($season === null) {
            return new \WP_REST_Response(['error' => 'Season not found.'], StatusCode::NOT_FOUND);
        }

        $playerId = (int)$request->get_param('player_id');
        $player   = $this->playerRepository->findById($playerId);
        if ($player === null) {
            return new \WP_REST_Response(['error' => 'Player not found.'], StatusCode::NOT_FOUND);
        }

        $existing = $this->seasonPlayerRepository->findBySeasonAndPlayer($season->id, $playerId);
        if ($existing !== null) {
            return new \WP_REST_Response(['error' => 'Player is already enrolled in this season.'], StatusCode::CONFLICT);
        }

        $category  = trim((string)($request->get_param('category') ?? ''));
        $eloRating = (int)($request->get_param('elo_rating') ?? $player->knsb_elo ?? 0);

        if ($category === '') {
            return new \WP_REST_Response(['error' => 'Category is required.'], StatusCode::BAD_REQUEST);
        }

        $seasonPlayer = $this->seasonPlayerRepository->create($season->id, $playerId, $category, $eloRating);

        return new \WP_REST_Response($this->serializer->serialize($seasonPlayer), StatusCode::CREATED);
    }

    public function removePlayer(\WP_REST_Request $request): \WP_REST_Response
    {
        $season = $this->seasonRepository->findById((int)$request->get_param('id'));
        if ($season === null) {
            return new \WP_REST_Response(['error' => 'Season not found.'], StatusCode::NOT_FOUND);
        }

        $seasonPlayer = $this->seasonPlayerRepository->findBySeasonAndPlayer(
            $season->id,
            (int)$request->get_param('player_id')
        );

        if ($seasonPlayer === null) {
            return new \WP_REST_Response(['error' => 'Player is not enrolled in this season.'], StatusCode::NOT_FOUND);
        }

        $this->seasonPlayerRepository->delete($seasonPlayer->id);

        return new \WP_REST_Response(null, StatusCode::NO_CONTENT);
    }
}
