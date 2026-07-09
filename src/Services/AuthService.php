<?php

declare(strict_types=1);

namespace SCS\Services;

use SCS\Entity\Enum\AdminStatus;
use SCS\Entity\Enum\MemberStatus;
use SCS\Entity\Enum\Role;
use SCS\Entity\Member;
use SCS\Exception\NotFoundException;
use SCS\Exception\UnauthorizedException;
use SCS\Repository\AdminRepository;
use SCS\Repository\MemberRepository;

class AuthService
{
    public function __construct(
        private readonly MemberRepository $memberRepository,
        private readonly AdminRepository $adminRepository,
        private readonly JwtService $jwtService,
        private readonly EmailNotificationService $emailNotificationService,
    ) {
    }

    /** @return array{token: string, role: string, player_id: int|null} */
    public function login(string $email, string $password): array
    {
        $member = $this->memberRepository->findByEmail($email);
        if ($member !== null) {
            if (!password_verify($password, (string)$member->password_hash)) {
                throw new UnauthorizedException('Invalid credentials.');
            }
            if ($member->status !== MemberStatus::Active) {
                throw new UnauthorizedException('Account is not active.');
            }

            return [
                'token'     => $this->jwtService->issue($member->id, Role::Member, $member->player_id),
                'role'      => Role::Member->value,
                'player_id' => $member->player_id,
            ];
        }

        $admin = $this->adminRepository->findByEmail($email);
        if ($admin !== null) {
            if (!password_verify($password, $admin->password_hash)) {
                throw new UnauthorizedException('Invalid credentials.');
            }
            if ($admin->status !== AdminStatus::Active) {
                throw new UnauthorizedException('Account is not active.');
            }

            return [
                'token'     => $this->jwtService->issue($admin->id, Role::Admin),
                'role'      => Role::Admin->value,
                'player_id' => null,
            ];
        }

        throw new UnauthorizedException('Invalid credentials.');
    }

    /**
     * Create a member account for a player and email them an invite to set a
     * password. The token is stored in plaintext (matching the reset flow) and
     * expires in 7 days — the window stated in the invite email. Throws a
     * UniqueConstraintViolationException if the email or the player already has
     * a member row; the caller maps that to a conflict.
     */
    public function inviteMember(int $playerId, string $email): Member
    {
        $token     = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable('+7 days');

        $member = $this->memberRepository->create($playerId, $email, $token, $expiresAt);
        $this->emailNotificationService->sendInvite($email, $token);

        return $member;
    }

    /**
     * Re-send an invite to a member who hasn't accepted yet: mint a fresh token
     * (invalidating the old link), reset the expiry, and email it again. The
     * email may be corrected in the same step. Throws a
     * UniqueConstraintViolationException if the new email is already in use.
     */
    public function resendInvite(Member $member, string $email): Member
    {
        $token     = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable('+7 days');

        $this->memberRepository->update($member->id, [
            'email'             => $email,
            'invite_token'      => $token,
            'invite_expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);
        $this->emailNotificationService->sendInvite($email, $token);

        return $this->memberRepository->findById($member->id);
    }

    /**
     * Check an invite token without consuming it, so the accept-invite page can
     * show a friendly landing before asking for a password. Distinguishes a bad
     * / already-used token ("invalid") from a still-recognised but lapsed one
     * ("expired"). This endpoint is public (a signed-out invitee hits it), so it
     * deliberately returns no member data — only the yes/no validity — to avoid
     * disclosing the invitee's email to anyone holding the link.
     *
     * @return array{valid: bool, reason?: string}
     */
    public function inviteTokenStatus(string $token): array
    {
        if ($token === '') {
            return ['valid' => false, 'reason' => 'invalid'];
        }

        $member = $this->memberRepository->findByInviteToken($token);
        if ($member === null) {
            return ['valid' => false, 'reason' => 'invalid'];
        }
        if ($member->invite_expires_at === null || $member->invite_expires_at < new \DateTimeImmutable()) {
            return ['valid' => false, 'reason' => 'expired'];
        }

        return ['valid' => true];
    }

    public function acceptInvite(string $token, string $password): void
    {
        $member = $this->memberRepository->findByInviteToken($token);
        if ($member === null) {
            throw new NotFoundException('Invalid or expired invite link.');
        }
        if ($member->invite_expires_at < new \DateTimeImmutable()) {
            throw new UnauthorizedException('Invite link has expired.');
        }

        $this->memberRepository->update($member->id, [
            'password_hash'     => password_hash($password, PASSWORD_BCRYPT),
            'invite_token'      => null,
            'invite_expires_at' => null,
            'status'            => MemberStatus::Active->value,
        ]);
    }

    public function initiatePasswordReset(string $email): void
    {
        $member = $this->memberRepository->findByEmail($email);
        if ($member === null || $member->status !== MemberStatus::Active) {
            // Silently return — don't reveal whether email exists
            return;
        }

        $resetToken = bin2hex(random_bytes(32));
        $expiresAt  = new \DateTimeImmutable('+1 hour');

        $this->memberRepository->update($member->id, [
            'reset_token'      => $resetToken,
            'reset_expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);

        $this->emailNotificationService->sendPasswordReset($member->email, $resetToken);
    }

    public function resetPassword(string $token, string $password): void
    {
        $member = $this->memberRepository->findByResetToken($token);
        if ($member === null) {
            throw new NotFoundException('Invalid or expired reset link.');
        }
        if ($member->reset_expires_at < new \DateTimeImmutable()) {
            throw new UnauthorizedException('Reset link has expired.');
        }

        $this->memberRepository->update($member->id, [
            'password_hash'    => password_hash($password, PASSWORD_BCRYPT),
            'reset_token'      => null,
            'reset_expires_at' => null,
        ]);
    }
}
