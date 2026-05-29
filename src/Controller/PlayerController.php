<?php

declare(strict_types=1);

namespace SCS\Controller;

use SCS\Http\StatusCode;
use SCS\Repository\PlayerRepository;
use SCS\Services\SerializerService;

class PlayerController
{
    public function __construct(
        private readonly PlayerRepository $playerRepository,
        private readonly SerializerService $serializer,
    ) {
    }

    public function index(\WP_REST_Request $request): \WP_REST_Response
    {
        $players = $this->playerRepository->findActive();

        return new \WP_REST_Response(array_map($this->serializer->serialize(...), $players), StatusCode::OK);
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response
    {
        $player = $this->playerRepository->findById((int)$request->get_param('id'));
        if ($player === null) {
            return new \WP_REST_Response(['error' => 'Player not found.'], StatusCode::NOT_FOUND);
        }

        return new \WP_REST_Response($this->serializer->serialize($player), StatusCode::OK);
    }

    public function store(\WP_REST_Request $request): \WP_REST_Response
    {
        $name = trim((string)$request->get_param('name'));
        if ($name === '') {
            return new \WP_REST_Response(['error' => 'Name is required.'], StatusCode::BAD_REQUEST);
        }

        $player = $this->playerRepository->create(
            name:          $name,
            knsb_id:       $request->get_param('knsb_id') !== null ? (string)$request->get_param('knsb_id') : null,
            knsb_elo:      $request->get_param('knsb_elo') !== null ? (int)$request->get_param('knsb_elo') : null,
            gender:        $request->get_param('gender') !== null ? (string)$request->get_param('gender') : null,
            date_of_birth: $request->get_param('date_of_birth') !== null ? (string)$request->get_param('date_of_birth') : null,
        );

        return new \WP_REST_Response($this->serializer->serialize($player), StatusCode::CREATED);
    }

    public function update(\WP_REST_Request $request): \WP_REST_Response
    {
        $player = $this->playerRepository->findById((int)$request->get_param('id'));
        if ($player === null) {
            return new \WP_REST_Response(['error' => 'Player not found.'], StatusCode::NOT_FOUND);
        }

        $data = array_filter([
            'name'          => $request->get_param('name') !== null ? trim((string)$request->get_param('name')) : null,
            'knsb_id'       => $request->get_param('knsb_id'),
            'knsb_elo'      => $request->get_param('knsb_elo') !== null ? (int)$request->get_param('knsb_elo') : null,
            'gender'        => $request->get_param('gender'),
            'date_of_birth' => $request->get_param('date_of_birth'),
            'active'        => $request->get_param('active') !== null ? (int)(bool)$request->get_param('active') : null,
        ], fn ($v) => $v !== null);

        if (empty($data)) {
            return new \WP_REST_Response(['error' => 'No fields to update.'], StatusCode::BAD_REQUEST);
        }

        $this->playerRepository->update($player->id, $data);

        return new \WP_REST_Response($this->serializer->serialize($this->playerRepository->findById($player->id)), StatusCode::OK);
    }
}
