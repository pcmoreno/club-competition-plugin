<?php

declare(strict_types=1);

namespace SCS\Controller;

use SCS\Exception\NotFoundException;
use SCS\Exception\ValidationException;
use SCS\Repository\MemberRepository;
use SCS\Repository\PlayerRepository;
use SCS\Request\CreatePlayerRequest;
use SCS\Request\UpdatePlayerRequest;
use SCS\Services\SerializerService;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class PlayerController extends RestController
{
    public function __construct(
        ValidatorInterface $validator,
        private readonly PlayerRepository $playerRepository,
        private readonly MemberRepository $memberRepository,
        private readonly SerializerService $serializer,
    ) {
        parent::__construct($validator);
    }

    /**
     * Full club roster (admin only) — every player, active or not, each
     * enriched with their member account's email + status (null when the
     * player has no login account). Admin-scoped because email is PII.
     */
    public function index(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () {
            // player_id => Member, so each player resolves its account in one
            // pass without an N+1 of findByPlayerId() calls.
            $membersByPlayer = [];
            foreach ($this->memberRepository->findAll() as $member) {
                $membersByPlayer[$member->player_id] = $member;
            }

            $players = array_map(function ($player) use ($membersByPlayer) {
                $data   = $this->serializer->serialize($player, SerializerService::GROUP_ADMIN);
                $member = $membersByPlayer[$player->id] ?? null;

                $data['email']         = $member?->email;
                $data['member_status'] = $member?->status->value;

                return $data;
            }, $this->playerRepository->findAll());

            return $this->ok($players);
        });
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $player = $this->playerRepository->findById((int)$request->get_param('id'));
            if ($player === null) {
                throw new NotFoundException('Player not found.');
            }

            return $this->ok($this->serializer->serialize($player));
        });
    }

    public function store(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $input = CreatePlayerRequest::fromRequest($request);
            $this->validate($input);

            $player = $this->playerRepository->create(
                name:          $input->name,
                knsb_id:       $input->knsb_id,
                knsb_elo:      $input->knsb_elo,
                gender:        $input->gender,
                date_of_birth: $input->date_of_birth,
            );

            return $this->created($this->serializer->serialize($player, SerializerService::GROUP_ADMIN));
        });
    }

    public function update(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $player = $this->playerRepository->findById((int)$request->get_param('id'));
            if ($player === null) {
                throw new NotFoundException('Player not found.');
            }

            $input = UpdatePlayerRequest::fromRequest($request);
            $this->validate($input);

            $data = $input->toUpdateData();
            if (empty($data)) {
                throw new ValidationException(['fields' => 'No fields to update.']);
            }

            $this->playerRepository->update($player->id, $data);

            return $this->ok($this->serializer->serialize($this->playerRepository->findById($player->id), SerializerService::GROUP_ADMIN));
        });
    }
}
