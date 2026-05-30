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
use SCS\Request\CreateSeasonRequest;
use SCS\Request\EnrollPlayerRequest;
use SCS\Request\UpdateSeasonRequest;
use SCS\Services\SerializerService;

class SeasonController extends RestController
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

            $seasonPlayers = $this->seasonPlayerRepository->findBySeason($season->id);

            return $this->ok([
                'season'  => $this->serializer->serialize($season),
                'players' => array_map($this->serializer->serialize(...), $seasonPlayers),
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

            return $this->created($this->serializer->serialize($season));
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

            return $this->ok($this->serializer->serialize($this->seasonRepository->findById($season->id)));
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
