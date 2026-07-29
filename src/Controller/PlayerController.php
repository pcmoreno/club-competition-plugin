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
use SCS\Services\PlayerMergeService;
use SCS\Services\PlayerTournamentService;
use SCS\Services\SerializerService;
use SCS\Services\TransactionManager;
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
        private readonly PlayerTournamentService $playerTournamentService,
        private readonly PlayerMergeService $playerMergeService,
        private readonly TransactionManager $transactions,
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

    /**
     * The seasons/tournaments a player is enrolled in (admin), newest first.
     * Each row pairs the player's enrolment (the Elo they entered with) with the
     * season it belongs to (name + status), so the admin player detail view can
     * list a player's whole competition history at a glance.
     */
    public function tournaments(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $player = $this->playerRepository->findById((int)$request->get_param('id'));
            if ($player === null) {
                throw new NotFoundException('Player not found.');
            }

            return $this->ok($this->playerTournamentService->enrollments($player->id));
        });
    }

    /**
     * Permanently delete a player and their member account (admin action). Only
     * allowed when the player is enrolled in no season/tournament: enrolments
     * carry games and standings, so a player with history can only be
     * deactivated. Deletes the member login first (if any), then the player.
     */
    public function destroy(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $player = $this->playerRepository->findById((int)$request->get_param('id'));
            if ($player === null) {
                throw new NotFoundException('Player not found.');
            }

            if ($this->playerTournamentService->isEnrolled($player->id)) {
                throw new ConflictException(
                    'This player is enrolled in one or more tournaments and can\'t be deleted. Deactivate them instead.'
                );
            }

            // One unit: a failure between the two deletes would leave a member
            // row pointing at a player id that no longer exists, and
            // AuthContextService only validates the member — so that account
            // would keep authenticating while /auth/me returns player: null.
            $this->transactions->transactional(function () use ($player): void {
                $member = $this->memberRepository->findByPlayerId($player->id);
                if ($member !== null) {
                    $this->memberRepository->delete($member->id);
                }
                $this->playerRepository->delete($player->id);
            });

            return $this->noContent();
        });
    }

    /**
     * Merge one player into another (admin action): the "source" player's whole
     * competition history moves to the player at {id} and the source row (plus
     * its member account, if any) is deleted. Refused when both share a season —
     * their two runs can't be fused. See PlayerMergeService for the full
     * contract.
     */
    public function merge(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $sourceId = (int)$request->get_param('source_id');
            if ($sourceId <= 0) {
                throw new ValidationException(['source_id' => 'A player to remove is required.']);
            }

            $this->playerMergeService->merge((int)$request->get_param('id'), $sourceId);

            return $this->noContent();
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

            // No account yet → create + invite. Invite pending, or a previously
            // revoked account → (re-)send a fresh token, which flips a revoked
            // member back to Invited. Already accepted (active) → nothing to send.
            $existing = $this->memberRepository->findByPlayerId($player->id);
            try {
                if ($existing === null) {
                    $member  = $this->authService->inviteMember($player->id, $input->email);
                    $created = true;
                } elseif (in_array($existing->status, [MemberStatus::Invited, MemberStatus::Revoked], true)) {
                    $member  = $this->authService->resendInvite($existing, $input->email);
                    $created = false;
                } else {
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

    /**
     * Revoke a player's member account (admin action): flip it to Revoked and
     * kill any live session immediately (AuthService bumps token_valid_after and
     * clears pending invite/reset tokens). The player row is untouched — only
     * their login is disabled. A revoked account can later be re-invited via
     * invite(), which issues a fresh token and returns it to Invited. Returns the
     * roster-shaped row so the client can refresh the Member column in place.
     */
    public function revoke(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $player = $this->playerRepository->findById((int)$request->get_param('id'));
            if ($player === null) {
                throw new NotFoundException('Player not found.');
            }

            $member = $this->memberRepository->findByPlayerId($player->id);
            if ($member === null) {
                throw new NotFoundException('This player has no member account to revoke.');
            }
            if ($member->status === MemberStatus::Revoked) {
                throw new ConflictException('This member account is already revoked.');
            }

            $this->authService->revokeMember($member);

            // revoke leaves the email untouched and sets the status to Revoked,
            // so the roster row can be rebuilt from what we already hold — no
            // re-fetch needed.
            $data                  = $this->serializer->serialize($player, SerializerService::GROUP_ADMIN);
            $data['email']         = $member->email;
            $data['member_status'] = MemberStatus::Revoked->value;

            return $this->ok($data);
        });
    }
}
