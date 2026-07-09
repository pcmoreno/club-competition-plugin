<?php

declare(strict_types=1);

namespace SCS\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use SCS\Entity\Enum\MemberStatus;
use SCS\Exception\ConflictException;
use SCS\Exception\NotFoundException;
use SCS\Exception\ValidationException;
use SCS\Repository\MemberRepository;
use SCS\Repository\PlayerRepository;
use SCS\Request\CreatePlayerRequest;
use SCS\Request\InviteMemberRequest;
use SCS\Request\UpdatePlayerRequest;
use SCS\Services\AuthService;
use SCS\Services\KnsbNameNormalizer;
use SCS\Services\KnsbRatingStore;
use SCS\Services\SerializerService;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class PlayerController extends RestController
{
    public function __construct(
        ValidatorInterface $validator,
        private readonly PlayerRepository $playerRepository,
        private readonly MemberRepository $memberRepository,
        private readonly KnsbRatingStore $knsbRatingStore,
        private readonly KnsbNameNormalizer $knsbNameNormalizer,
        private readonly AuthService $authService,
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
                name:       $input->name,
                knsb_id:    $input->knsb_id,
                knsb_elo:   $input->knsb_elo,
                gender:     $input->gender,
                birth_year: $input->birth_year,
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

    /**
     * Apply the player's authoritative KNSB data (name, birth year, rating) from
     * the last-fetched list (admin). Matches by knsb_id only; the list itself is
     * refreshed by `wp scs fetch-knsb-ratings`. KNSB is the source of truth, so
     * this overwrites the player's name (normalised to the club's "given-name
     * first" convention) and birth year, correcting manual entry mistakes.
     */
    public function applyKnsbRating(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $player = $this->playerRepository->findById((int)$request->get_param('id'));
            if ($player === null) {
                throw new NotFoundException('Player not found.');
            }
            if ($player->knsb_id === null || $player->knsb_id === '') {
                throw new ValidationException(['knsb_id' => 'This player has no KNSB id to sync.']);
            }
            if ($this->knsbRatingStore->read() === null) {
                throw new ConflictException('No KNSB rating list has been fetched yet.');
            }

            $row = $this->knsbRatingStore->findRating($player->knsb_id);
            if ($row === null) {
                throw new NotFoundException('This KNSB id is not in the current rating list.');
            }

            $name = $this->knsbNameNormalizer->normalize((string)$row['name']);
            if ($name === '') {
                $name = $player->name;
            }

            try {
                $this->playerRepository->applyKnsbData(
                    $player->id,
                    $name,
                    $row['birth_year'] ?? null,
                    (int)$row['rating'],
                    current_time('mysql'),
                );
            } catch (UniqueConstraintViolationException) {
                throw new ConflictException(sprintf(
                    'Another player is already named "%s"; resolve the duplicate before syncing.',
                    $name,
                ));
            }

            // The client invalidates and refetches the roster, so returning the
            // updated player (name + birth_year + knsb_elo + knsb_synced_at)
            // suffices here.
            return $this->ok($this->serializer->serialize(
                $this->playerRepository->findById($player->id),
                SerializerService::GROUP_ADMIN
            ));
        });
    }

    /**
     * Invite a player to become a member (admin action): create their member
     * account and email an invite to set a password. Grants ROLE_MEMBER only —
     * never admin. The player must not already have an account, and the email
     * must be unused. On success the roster's Member column flips "—" → "invited".
     */
    public function invite(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $player = $this->playerRepository->findById((int)$request->get_param('id'));
            if ($player === null) {
                throw new NotFoundException('Player not found.');
            }

            $input = InviteMemberRequest::fromRequest($request);
            $this->validate($input);

            // No account yet → create + invite. Invite pending → re-send with a
            // fresh token. Already accepted (active) → nothing to (re)send.
            $existing = $this->memberRepository->findByPlayerId($player->id);
            try {
                if ($existing === null) {
                    $member  = $this->authService->inviteMember($player->id, $input->email);
                    $created = true;
                } elseif ($existing->status === MemberStatus::Invited) {
                    $member  = $this->authService->resendInvite($existing, $input->email);
                    $created = false;
                } else {
                    // TODO: when a revoke path is added, branch this message per
                    // status — a Revoked member isn't "active" and could be
                    // re-invited rather than rejected here.
                    throw new ConflictException('This player already has an active member account.');
                }
            } catch (UniqueConstraintViolationException) {
                throw new ConflictException('That email address is already in use.');
            }

            // Return the roster-shaped row (player + email + member_status). This
            // is the admin players list, so GROUP_ADMIN = which fields are shown
            // (adds email/created_at) — not a role. The member is ROLE_MEMBER.
            $data                  = $this->serializer->serialize($player, SerializerService::GROUP_ADMIN);
            $data['email']         = $member->email;
            $data['member_status'] = $member->status->value;

            return $created ? $this->created($data) : $this->ok($data);
        });
    }
}
