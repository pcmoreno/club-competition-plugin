<?php

declare(strict_types=1);

namespace SCS\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use SCS\Entity\Admin;
use SCS\Entity\Enum\AdminStatus;
use SCS\Exception\ConflictException;
use SCS\Exception\NotFoundException;
use SCS\Exception\UnauthorizedException;
use SCS\Repository\AdminRepository;
use SCS\Repository\MemberRepository;
use SCS\Repository\SeasonContactRepository;
use SCS\Request\InviteAdminRequest;
use SCS\Request\ResendAdminInviteRequest;
use SCS\Services\AuthContextService;
use SCS\Services\AuthService;
use SCS\Services\SerializerService;
use SCS\Services\TransactionManager;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The plugin's own admin accounts: the Admins tab lists them, and the first
 * admin can invite and remove the others.
 *
 * Admins are normally created with `wp scs create-admin`, which production has
 * no convenient way to run — so a second admin was unreachable there. Rather
 * than open account creation to every admin, the account that set the club up
 * (the lowest id — there is no role column and no created_by) is treated as the
 * one that may grow the list. Everyone else sees the tab read-only.
 *
 * Admin-gated throughout: these are staff names and email addresses.
 */
class AdminController extends RestController
{
    public function __construct(
        ValidatorInterface $validator,
        private readonly AdminRepository $adminRepository,
        private readonly MemberRepository $memberRepository,
        private readonly SeasonContactRepository $seasonContactRepository,
        private readonly AuthService $authService,
        private readonly AuthContextService $authContext,
        private readonly SerializerService $serializer,
        private readonly TransactionManager $transactions,
    ) {
        parent::__construct($validator);
    }

    /**
     * Every admin, pending invites included, each flagged with whether it is the
     * first one. The flag is computed here rather than re-derived in the
     * frontend so the rule that gates the write routes is stated once.
     *
     * The tournament-contacts picker reads this too and filters to active: an
     * admin who hasn't accepted yet shouldn't be pickable as a recipient.
     */
    public function index(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () {
            $firstId = $this->adminRepository->firstAdminId();

            return $this->ok(array_map(
                fn (Admin $a) => $this->serializer->serialize($a, SerializerService::GROUP_ADMIN)
                    + ['is_super_admin' => $a->id === $firstId],
                $this->adminRepository->findAll()
            ));
        });
    }

    /**
     * Invite a new admin: creates the account with no password and emails a
     * one-time link. Grants ROLE_ADMIN once accepted — the invitee can do
     * everything the inviter can, bar inviting further admins.
     */
    public function store(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $this->requireSuperAdmin();

            $input = InviteAdminRequest::fromRequest($request);
            $this->validate($input);

            if ($this->adminRepository->findByEmail($input->email) !== null) {
                throw new ConflictException(sprintf('An admin with email "%s" already exists.', $input->email));
            }
            $this->requireEmailFreeOfMemberAccount($input->email);

            try {
                $admin = $this->authService->inviteAdmin($input->name, $input->email);
            } catch (UniqueConstraintViolationException) {
                // Lost the race against a concurrent invite of the same address.
                throw new ConflictException('That email address is already in use.');
            }

            return $this->created($this->serializedAdmin($admin));
        });
    }

    /**
     * Re-send a pending invite with a fresh token, optionally to a corrected
     * address. Only for an admin who hasn't accepted yet: an active account has
     * a password and should use the password-reset flow instead.
     */
    public function invite(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $this->requireSuperAdmin();

            $admin = $this->requireAdmin((int)$request->get_param('id'));
            if ($admin->status !== AdminStatus::Invited) {
                throw new ConflictException('This admin has already activated their account.');
            }

            $input = ResendAdminInviteRequest::fromRequest($request);
            $this->validate($input);

            // The resend may correct the address, so it needs the same check.
            if ($input->email !== $admin->email) {
                $this->requireEmailFreeOfMemberAccount($input->email);
            }

            try {
                $updated = $this->authService->resendAdminInvite($admin, $input->email);
            } catch (UniqueConstraintViolationException) {
                throw new ConflictException('That email address is already in use.');
            }

            return $this->ok($this->serializedAdmin($updated));
        });
    }

    /**
     * Remove an admin outright — the row goes, rather than flipping to revoked.
     * Their session dies with it: AuthContextService re-reads the account on
     * every request and fails closed when it's gone.
     *
     * The first admin can't be deleted, which (since only they can call this)
     * also means they can't delete themselves and leave the club with no account
     * able to invite.
     */
    public function destroy(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $this->requireSuperAdmin();

            $admin = $this->requireAdmin((int)$request->get_param('id'));
            if ($admin->id === $this->adminRepository->firstAdminId()) {
                throw new ConflictException('The first admin account cannot be deleted.');
            }

            // No FK cascade, so their tournament-contact rows go first — and
            // transactionally, or a failure between the two would leave contact
            // rows pointing at an admin that no longer exists.
            $this->transactions->transactional(function () use ($admin): void {
                $this->seasonContactRepository->deleteByAdmin($admin->id);
                $this->adminRepository->delete($admin->id);
            });

            return $this->ok(['deleted' => true]);
        });
    }

    /** @return array<string, mixed> */
    private function serializedAdmin(Admin $admin): array
    {
        return $this->serializer->serialize($admin, SerializerService::GROUP_ADMIN)
            + ['is_super_admin' => $admin->id === $this->adminRepository->firstAdminId()];
    }

    /**
     * Refuse an address that already signs in as a member.
     *
     * Members and admins are separate tables with separate passwords, and
     * AuthService::attemptLogin checks members first — so an address that is
     * both would always resolve to the member account. The admin account would
     * be unreachable: its password fails against the member's hash, and the
     * member password logs them in as a member. Since most admins are also
     * players, this is the likely case rather than an exotic one, and it has to
     * fail here with a reason rather than silently produce a dead account.
     */
    private function requireEmailFreeOfMemberAccount(string $email): void
    {
        if ($this->memberRepository->findByEmail($email) !== null) {
            throw new ConflictException(sprintf(
                'The address "%s" is already a member login. An admin account needs its own address, because a shared one would always sign in as the member.',
                $email
            ));
        }
    }

    private function requireAdmin(int $id): Admin
    {
        $admin = $this->adminRepository->findById($id);
        if ($admin === null) {
            throw new NotFoundException('Admin not found.');
        }

        return $admin;
    }

    /**
     * The route's permission callback already proved ROLE_ADMIN and a valid CSRF
     * token; this is the narrower gate on top. Enforced here rather than only
     * hidden in the UI — the endpoints are reachable by any signed-in admin.
     */
    private function requireSuperAdmin(): void
    {
        $claims  = $this->authContext->currentClaims();
        $firstId = $this->adminRepository->firstAdminId();

        if ($claims === null || $firstId === null || $claims['sub'] !== $firstId) {
            throw new UnauthorizedException('Only the first admin account can manage admins.');
        }
    }
}
