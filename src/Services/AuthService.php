<?php

declare(strict_types=1);

namespace SCS\Services;

use SCS\Entity\Enum\AdminStatus;
use SCS\Entity\Enum\MemberStatus;
use SCS\Entity\Enum\Role;
use SCS\Entity\Member;
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

    /**
     * Bcrypt hash of a discarded 256-bit random value — never a real
     * account's password, and its plaintext was never captured or stored
     * anywhere. Its match/no-match result must never be branched on (see
     * attemptLogin()); it exists purely to give login() a bcrypt-shaped
     * delay when there's no real hash to check, closing the timing gap that
     * would otherwise let an attacker enumerate registered emails.
     */
    private const DUMMY_PASSWORD_HASH = '$2y$10$2S8lLgH1hUxSSOVRpPmDD.OksUzW5AO3aCRyOTKaWCAh3W.xRxbPa';

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
                'email'     => $admin->email,
            ];
        }

        // No real password to check (unknown email, or a member that exists
        // but hasn't set one yet): burn the same bcrypt-shaped time as a real
        // check so the response isn't a timing tell, but the result is never
        // inspected — it can't be used to skip ahead, no matter what's passed.
        password_verify($password, self::DUMMY_PASSWORD_HASH);

        throw new UnauthorizedException('Invalid credentials.');
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
        $token     = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable('+7 days');

        $this->memberRepository->update($member->id, [
            'email'             => $email,
            'invite_token'      => self::hashToken($token),
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

        $member = $this->memberRepository->findByInviteTokenHash(self::hashToken($token));
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
        $member = $this->memberRepository->findByInviteTokenHash(self::hashToken($token));
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
            'password_hash'     => password_hash($password, PASSWORD_BCRYPT),
            'reset_token'       => null,
            'reset_expires_at'  => null,
            'token_valid_after' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }
}
