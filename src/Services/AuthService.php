<?php

declare(strict_types=1);

namespace SCS\Services;

use SCS\Entity\Admin;
use SCS\Entity\Enum\AdminStatus;
use SCS\Entity\Enum\MemberStatus;
use SCS\Entity\Enum\Role;
use SCS\Entity\Member;
use SCS\Exception\ConflictException;
use SCS\Exception\NotFoundException;
use SCS\Exception\TooManyRequestsException;
use SCS\Exception\UnauthorizedException;
use SCS\Repository\AdminRepository;
use SCS\Repository\MemberRepository;

class AuthService
{
    private const LOGIN_MAX_ATTEMPTS_PER_ACCOUNT = 5;
    private const LOGIN_MAX_ATTEMPTS_PER_IP       = 20;
    private const LOGIN_DECAY_SECONDS             = 900; // 15 minutes

    private const RESET_MAX_ATTEMPTS_PER_ACCOUNT = 3;
    private const RESET_MAX_ATTEMPTS_PER_IP       = 10;
    private const RESET_DECAY_SECONDS             = 3600; // 1 hour

    // Admin invites mail a caller-supplied address, and a resend rewrites the
    // pending admin's address to whatever is submitted — so both are metered
    // even though only the first admin can reach them.
    private const ADMIN_INVITE_MAX_PER_ADDRESS = 3;
    private const ADMIN_INVITE_MAX_PER_IP      = 10;
    private const ADMIN_INVITE_DECAY_SECONDS   = 3600; // 1 hour

    /**
     * Argon2id hash of a discarded 256-bit random value — never a real
     * account's password, and its plaintext was never captured or stored
     * anywhere. Its match/no-match result must never be branched on (see
     * attemptLogin()); it exists purely to give login() an Argon2id-shaped
     * delay when there's no real hash to check, closing the timing gap that
     * would otherwise let an attacker enumerate registered emails. It uses
     * the default Argon2id cost params (m=65536,t=4,p=1) so it burns the same
     * time as a real verify against a freshly hashed password — keep it in
     * sync if PASSWORD_HASH_ALGO's params ever change.
     */
    private const DUMMY_PASSWORD_HASH = '$argon2id$v=19$m=65536,t=4,p=1$N0JnR2NRZ3FPS3Mvc1I2Rg$tpTz9PwWMcSUfc3etcYI55lXFE0fvmTj9NQn6IbJOmY';

    /**
     * Algorithm for all new password hashes. Argon2id is memory-hard (unlike
     * bcrypt) and has no 72-byte input truncation. password_verify() reads the
     * algorithm from each stored hash, so any pre-existing bcrypt hashes would
     * still verify — but there are none in production, so no migration path is
     * needed here.
     */
    private const PASSWORD_HASH_ALGO = PASSWORD_ARGON2ID;

    public function __construct(
        private readonly MemberRepository $memberRepository,
        private readonly AdminRepository $adminRepository,
        private readonly JwtService $jwtService,
        private readonly EmailNotificationService $emailNotificationService,
        private readonly RateLimiterService $rateLimiter,
    ) {
    }

    /**
     * Invite/reset tokens are high-entropy (256-bit) and single-purpose, so a
     * fast, unsalted hash is fine — unlike passwords, there's no risk of an
     * offline dictionary attack. Storing this instead of the raw token means a
     * leaked DB row (or backup) alone can't yield a usable link.
     */
    private static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Hash a plaintext password for storage. Single choke point for the
     * algorithm so invite/reset/change-password/create-admin all stay
     * consistent.
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, self::PASSWORD_HASH_ALGO);
    }

    /**
     * Whether the public "create the first admin" bootstrap is still open —
     * true only while the admins table is empty. Drives both the UI's button
     * visibility and the guard on bootstrapFirstAdmin() below.
     */
    public function adminBootstrapAvailable(): bool
    {
        return $this->adminRepository->countAll() === 0;
    }

    /**
     * Break-glass creation of the very first admin from the public UI, for when
     * WP-CLI (the normal `wp scs create-admin` path) isn't reachable on the
     * host. Deliberately unauthenticated — there is no admin yet to authorise
     * it — so the zero-admins invariant IS the authorisation: this is re-checked
     * here, not just hidden in the UI, and the method turns inert the instant
     * any admin row exists.
     */
    public function bootstrapFirstAdmin(string $name, string $email, string $password): Admin
    {
        if ($this->adminRepository->countAll() > 0) {
            throw new ConflictException('Admin setup is already complete.');
        }
        if ($this->adminRepository->findByEmail($email) !== null) {
            throw new ConflictException(sprintf('An admin with email "%s" already exists.', $email));
        }

        return $this->adminRepository->create($name, $email, self::hashPassword($password));
    }

    /**
     * Record an auth-relevant failure/lockout to the PHP error log so a
     * brute-force, enumeration, or credential-stuffing campaign leaves a
     * detectable trace beyond the decaying rate-limit counters. Logs the
     * account identifier and source IP — never the attempted password.
     */
    private function logAuthEvent(string $event, string $email, string $ip): void
    {
        error_log(sprintf('[SCS auth] %s: account=%s ip=%s', $event, $email, $ip));
    }

    /**
     * @return array{token: string, role: string, player_id: int|null, email: string}
     *
     * @throws TooManyRequestsException when either the account or the source
     * IP has exceeded the failed-attempt threshold within the decay window.
     * A successful login clears only the per-account counter — see below.
     */
    public function login(string $email, string $password, string $ip): array
    {
        $ipKey    = 'login_ip_' . $ip;
        $emailKey = 'login_email_' . strtolower($email);

        if ($this->rateLimiter->tooManyAttempts($ipKey, self::LOGIN_MAX_ATTEMPTS_PER_IP)
            || $this->rateLimiter->tooManyAttempts($emailKey, self::LOGIN_MAX_ATTEMPTS_PER_ACCOUNT)) {
            $this->logAuthEvent('login blocked (rate limit)', $email, $ip);

            throw new TooManyRequestsException('Too many login attempts. Please try again later.');
        }

        try {
            $result = $this->attemptLogin($email, $password);
        } catch (UnauthorizedException $e) {
            $this->rateLimiter->hit($ipKey, self::LOGIN_DECAY_SECONDS);
            $this->rateLimiter->hit($emailKey, self::LOGIN_DECAY_SECONDS);
            $this->logAuthEvent('login failed', $email, $ip);

            throw $e;
        }

        // Clear only the per-account counter: the account owner just proved they
        // are who they claim, so their own failure budget resets. The per-IP
        // counter is deliberately NOT cleared — otherwise anyone with a single
        // valid credential (e.g. a throwaway account) on an IP could log in to
        // zero the shared IP-wide failure budget and resume password-spraying.
        // It decays on its own instead.
        $this->rateLimiter->clear($emailKey);

        return $result;
    }

    /** @return array{token: string, role: string, player_id: int|null, email: string} */
    private function attemptLogin(string $email, string $password): array
    {
        // password_hash !== null excludes invited members who haven't set a
        // password yet — they fall through to the dummy-verify branch below,
        // same as an unknown email, instead of comparing against ''.
        $member = $this->memberRepository->findByEmail($email);
        if ($member !== null && $member->password_hash !== null) {
            if (!password_verify($password, $member->password_hash)) {
                throw new UnauthorizedException('Invalid credentials.');
            }
            if ($member->status !== MemberStatus::Active) {
                throw new UnauthorizedException('Account is not active.');
            }

            return [
                'token'     => $this->jwtService->issue($member->id, Role::Member, $member->player_id),
                'role'      => Role::Member->value,
                'player_id' => $member->player_id,
                'email'     => $member->email,
            ];
        }

        // password_hash !== null excludes an invited admin who hasn't set one
        // yet, exactly as above — they fall through to the dummy verify rather
        // than being compared against null.
        $admin = $this->adminRepository->findByEmail($email);
        if ($admin !== null && $admin->password_hash !== null) {
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
                'email'     => $admin->email,
            ];
        }

        // No real password to check (unknown email, or a member that exists
        // but hasn't set one yet): burn the same Argon2id-shaped time as a real
        // check so the response isn't a timing tell, but the result is never
        // inspected — it can't be used to skip ahead, no matter what's passed.
        password_verify($password, self::DUMMY_PASSWORD_HASH);

        throw new UnauthorizedException('Invalid credentials.');
    }

    // Hit unconditionally before the work, like initiatePasswordReset, so the
    // counter meters attempts against unknown addresses too.
    private function throttleAdminInvite(string $email, string $ip): void
    {
        $ipKey    = 'admin_invite_ip_' . $ip;
        $emailKey = 'admin_invite_email_' . strtolower($email);

        if ($this->rateLimiter->tooManyAttempts($ipKey, self::ADMIN_INVITE_MAX_PER_IP)
            || $this->rateLimiter->tooManyAttempts($emailKey, self::ADMIN_INVITE_MAX_PER_ADDRESS)) {
            $this->logAuthEvent('admin invite blocked (rate limit)', $email, $ip);

            throw new TooManyRequestsException('Too many admin invites sent. Please try again later.');
        }

        $this->rateLimiter->hit($ipKey, self::ADMIN_INVITE_DECAY_SECONDS);
        $this->rateLimiter->hit($emailKey, self::ADMIN_INVITE_DECAY_SECONDS);
    }

    // One address, one login. attemptLogin resolves members first, so an address
    // in both tables silently locks the admin out of their own account.
    private function assertNotAnAdminAddress(string $email): void
    {
        if ($this->adminRepository->findByEmail($email) !== null) {
            throw new ConflictException(sprintf(
                'The address "%s" already signs in as an admin. A member account needs its own address, because a shared one would lock the admin out.',
                $email
            ));
        }
    }

    private function assertNotAMemberAddress(string $email): void
    {
        if ($this->memberRepository->findByEmail($email) !== null) {
            throw new ConflictException(sprintf(
                'The address "%s" is already a member login. An admin account needs its own address, because a shared one would always sign in as the member.',
                $email
            ));
        }
    }

    /**
     * Create a member account for a player and email them an invite to set a
     * password. Only the SHA-256 hash of the token is stored (matching the
     * reset flow) — a DB read alone can't yield a usable link, since the raw
     * token only ever leaves this process in the email. Expires in 7 days —
     * the window stated in the invite email. Throws a
     * UniqueConstraintViolationException if the email or the player already has
     * a member row; the caller maps that to a conflict.
     */
    public function inviteMember(int $playerId, string $email): Member
    {
        $this->assertNotAnAdminAddress($email);

        $token     = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable('+7 days');

        $member = $this->memberRepository->create($playerId, $email, self::hashToken($token), $expiresAt);
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
        $this->assertNotAnAdminAddress($email);

        $token     = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable('+7 days');

        $this->memberRepository->update($member->id, [
            'email'             => $email,
            'status'            => MemberStatus::Invited->value,
            'invite_token'      => self::hashToken($token),
            'invite_expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);
        $this->emailNotificationService->sendInvite($email, $token);

        return $this->memberRepository->findById($member->id);
    }

    /**
     * Invite someone to become an admin, mirroring inviteMember: the account row
     * exists immediately but holds no password, so it can't sign in until the
     * emailed link is followed. Only the first admin may call this — the check
     * is in AdminController, since it's an authorization rule, not an account one.
     *
     * Throws a UniqueConstraintViolationException if the email is already an
     * admin; the caller maps that to a conflict.
     */
    public function inviteAdmin(string $name, string $email, string $ip): Admin
    {
        $this->assertNotAMemberAddress($email);
        $this->throttleAdminInvite($email, $ip);

        $token     = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable('+7 days');

        $admin = $this->adminRepository->createInvited($name, $email, self::hashToken($token), $expiresAt);
        $this->emailNotificationService->sendAdminInvite($email, $token);

        return $admin;
    }

    /**
     * Re-send an admin invite: fresh token (the old link stops working), reset
     * expiry, and the email may be corrected in the same step. Without this a
     * lapsed or mistyped invite would be unrecoverable — the email column is
     * unique, so the same address can't simply be invited again.
     */
    public function resendAdminInvite(Admin $admin, string $email, string $ip): Admin
    {
        $this->assertNotAMemberAddress($email);
        $this->throttleAdminInvite($email, $ip);

        $token     = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable('+7 days');

        $this->adminRepository->update($admin->id, [
            'email'             => $email,
            'status'            => AdminStatus::Invited->value,
            'invite_token'      => self::hashToken($token),
            'invite_expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);
        $this->emailNotificationService->sendAdminInvite($email, $token);

        return $this->adminRepository->findById($admin->id)
            ?? throw new \RuntimeException('Admin disappeared while its invite was being re-sent.');
    }

    /**
     * Check an invite token without consuming it, so the accept-invite page can
     * show a friendly landing before asking for a password. Distinguishes a bad
     * / already-used token ("invalid") from a still-recognised but lapsed one
     * ("expired"). This endpoint is public (a signed-out invitee hits it), so it
     * deliberately returns no member data — only the yes/no validity — to avoid
     * disclosing the invitee's email to anyone holding the link.
     *
     * Members and admins are invited with the same kind of token and the same
     * link, so both tables are consulted; which one the token belongs to is
     * deliberately not reported, for the same reason the email isn't.
     *
     * @return array{valid: bool, reason?: string}
     */
    public function inviteTokenStatus(string $token): array
    {
        if ($token === '') {
            return ['valid' => false, 'reason' => 'invalid'];
        }

        // Member or Admin — both carry the same two invite columns, and all the
        // caller is told is whether the link still works.
        $hash    = self::hashToken($token);
        $invited = $this->memberRepository->findByInviteTokenHash($hash)
            ?? $this->adminRepository->findByInviteTokenHash($hash);

        if ($invited === null) {
            return ['valid' => false, 'reason' => 'invalid'];
        }
        if ($invited->invite_expires_at === null || $invited->invite_expires_at < new \DateTimeImmutable()) {
            return ['valid' => false, 'reason' => 'expired'];
        }

        return ['valid' => true];
    }

    /** Members first, then admins — one endpoint and one page serve both. */
    public function acceptInvite(string $token, string $password): void
    {
        $hash   = self::hashToken($token);
        $member = $this->memberRepository->findByInviteTokenHash($hash);

        if ($member === null) {
            $this->acceptAdminInvite($hash, $password);

            return;
        }

        if ($member->invite_expires_at < new \DateTimeImmutable()) {
            throw new UnauthorizedException('Invite link has expired.');
        }

        $this->memberRepository->update($member->id, [
            'password_hash'     => self::hashPassword($password),
            'invite_token'      => null,
            'invite_expires_at' => null,
            'status'            => MemberStatus::Active->value,
            'token_valid_after' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /** Setting the password is what turns an invited admin into an active one. */
    private function acceptAdminInvite(string $hash, string $password): void
    {
        $admin = $this->adminRepository->findByInviteTokenHash($hash);
        if ($admin === null) {
            throw new NotFoundException('Invalid or expired invite link.');
        }
        if ($admin->invite_expires_at === null || $admin->invite_expires_at < new \DateTimeImmutable()) {
            throw new UnauthorizedException('Invite link has expired.');
        }

        $this->adminRepository->update($admin->id, [
            'password_hash'     => self::hashPassword($password),
            'invite_token'      => null,
            'invite_expires_at' => null,
            'status'            => AdminStatus::Active->value,
            'token_valid_after' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @throws TooManyRequestsException when either the account or the source
     * IP has exceeded the request threshold within the decay window. Hit
     * unconditionally (before the lookup) so the counter throttles bombing
     * attempts against unregistered emails too, not just valid ones.
     */
    public function initiatePasswordReset(string $email, string $ip): void
    {
        $ipKey    = 'reset_ip_' . $ip;
        $emailKey = 'reset_email_' . strtolower($email);

        if ($this->rateLimiter->tooManyAttempts($ipKey, self::RESET_MAX_ATTEMPTS_PER_IP)
            || $this->rateLimiter->tooManyAttempts($emailKey, self::RESET_MAX_ATTEMPTS_PER_ACCOUNT)) {
            $this->logAuthEvent('password reset blocked (rate limit)', $email, $ip);

            throw new TooManyRequestsException('Too many password reset requests. Please try again later.');
        }

        $this->rateLimiter->hit($ipKey, self::RESET_DECAY_SECONDS);
        $this->rateLimiter->hit($emailKey, self::RESET_DECAY_SECONDS);

        $member = $this->memberRepository->findByEmail($email);
        if ($member === null || $member->status !== MemberStatus::Active) {
            // Silently return — don't reveal whether email exists
            return;
        }

        $resetToken = bin2hex(random_bytes(32));
        $expiresAt  = new \DateTimeImmutable('+1 hour');

        $this->memberRepository->update($member->id, [
            'reset_token'      => self::hashToken($resetToken),
            'reset_expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);

        $this->emailNotificationService->sendPasswordReset($member->email, $resetToken);
    }

    public function resetPassword(string $token, string $password): void
    {
        $member = $this->memberRepository->findByResetTokenHash(self::hashToken($token));
        if ($member === null) {
            throw new NotFoundException('Invalid or expired reset link.');
        }
        if ($member->reset_expires_at < new \DateTimeImmutable()) {
            throw new UnauthorizedException('Reset link has expired.');
        }

        $this->memberRepository->update($member->id, [
            'password_hash'     => self::hashPassword($password),
            'reset_token'       => null,
            'reset_expires_at'  => null,
            'token_valid_after' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Change the signed-in user's own password after re-verifying their current
     * one. Works for both members and admins (dispatched on the token's role).
     *
     * Bumps `token_valid_after` to now, which invalidates every JWT issued
     * before this moment — i.e. all *other* active sessions are logged out. A
     * fresh token is then issued and returned so the caller can replace the
     * current session's cookie and keep the user signed in here. (Issued after
     * the bump, so its `iat` is >= the new floor and it stays valid.)
     *
     * @param array{sub: int, role: string, pid: int|null, iat: int} $claims
     *
     * @throws UnauthorizedException when the account no longer exists or the
     * supplied current password doesn't match.
     */
    public function changePassword(array $claims, string $currentPassword, string $newPassword): string
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($claims['role'] === Role::Admin->value) {
            // As with members below, a null hash means the account was invited
            // but never activated: there is no current password to verify.
            $admin = $this->adminRepository->findById($claims['sub']);
            if ($admin === null || $admin->password_hash === null) {
                throw new UnauthorizedException('Account not found.');
            }
            if (!password_verify($currentPassword, $admin->password_hash)) {
                throw new UnauthorizedException('Current password is incorrect.');
            }

            $this->adminRepository->update($admin->id, [
                'password_hash'     => self::hashPassword($newPassword),
                'token_valid_after' => $now,
            ]);

            return $this->jwtService->issue($admin->id, Role::Admin);
        }

        // password_hash === null excludes an invited member who hasn't set one
        // yet — they have no current password to verify against.
        $member = $this->memberRepository->findById($claims['sub']);
        if ($member === null || $member->password_hash === null) {
            throw new UnauthorizedException('Account not found.');
        }
        if (!password_verify($currentPassword, $member->password_hash)) {
            throw new UnauthorizedException('Current password is incorrect.');
        }

        $this->memberRepository->update($member->id, [
            'password_hash'     => self::hashPassword($newPassword),
            'token_valid_after' => $now,
        ]);

        return $this->jwtService->issue($member->id, Role::Member, $member->player_id);
    }

    /**
     * Revoke a member account (admin action). Flips the status to Revoked and
     * bumps `token_valid_after` to now so every JWT already issued to them is
     * rejected on its next request — the account is locked out immediately, not
     * just at expiry. Pending invite/reset tokens are cleared so no in-flight
     * link can resurrect access. The player row is left untouched; re-inviting
     * later (resendInvite) issues a fresh token and returns them to Invited.
     */
    public function revokeMember(Member $member): void
    {
        $this->memberRepository->update($member->id, [
            'status'            => MemberStatus::Revoked->value,
            'token_valid_after' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'invite_token'      => null,
            'invite_expires_at' => null,
            'reset_token'       => null,
            'reset_expires_at'  => null,
        ]);
    }
}
