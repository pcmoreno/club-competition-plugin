<?php

declare(strict_types=1);

namespace SCS\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use SCS\Entity\Admin;
use SCS\Entity\Enum\AdminStatus;
use SCS\Exception\ConflictException;
use SCS\Exception\ForbiddenException;
use SCS\Exception\NotFoundException;
use SCS\Repository\AdminRepository;
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

            // AuthService rejects an address that already backs a member login.
            try {
                $admin = $this->authService->inviteAdmin($input->name, $input->email, $this->clientIp());
            } catch (UniqueConstraintViolationException) {
                // Lost the race against a concurrent invite of the same address.
                throw new ConflictException('That email address is already in use.');
            }

            return $this->created($this->serializedAdmin($admin));
        });
    }

    // Re-send a pending invite with a fresh token, optionally to a corrected
    // address. Refused once accepted — and there is no admin password reset, so
    // recovery from there is delete + re-invite, losing their contact rows.
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

            try {
                $updated = $this->authService->resendAdminInvite($admin, $input->email, $this->clientIp());
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

    private function requireAdmin(int $id): Admin
    {
        $admin = $this->adminRepository->findById($id);
        if ($admin === null) {
            throw new NotFoundException('Admin not found.');
        }

        return $admin;
    }

    // The narrower gate on top of $isAdmin, enforced here rather than only
    // hidden in the UI — the routes are reachable by any signed-in admin.
    private function requireSuperAdmin(): void
    {
        $claims  = $this->authContext->currentClaims();
        $firstId = $this->adminRepository->firstAdminId();

        if ($claims === null || $firstId === null || $claims['sub'] !== $firstId) {
            throw new ForbiddenException('Only the first admin account can manage admins.');
        }
    }
}
