<?php

declare(strict_types=1);

namespace SCS\Services;

use SCS\Entity\Enum\AdminStatus;
use SCS\Entity\Enum\MemberStatus;
use SCS\Entity\Enum\Role;
use SCS\Repository\AdminRepository;
use SCS\Repository\MemberRepository;

/**
 * Resolves the signed-in account from the JWT cookie, re-validated against
 * the database on every call. A signature-valid, unexpired JWT is not enough
 * on its own: it can't reflect a password change, deactivation, or deletion
 * that happened after it was issued (up to 24h of drift otherwise). Two
 * checks close that gap: the account must still exist and be Active, and its
 * `token_valid_after` floor (bumped on password change) must not be after
 * the token's `iat`.
 */
class AuthContextService
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly MemberRepository $memberRepository,
        private readonly AdminRepository $adminRepository,
    ) {
    }

    /**
     * Runs during the REST permission callback, before RestController::handle()'s
     * try/catch exists — a DB error here (e.g. a deadlock, not just an outage)
     * would otherwise reach WordPress as an uncaught exception. Fail closed:
     * treat it as "not authenticated" rather than leak internals or 500.
     *
     * @return array{sub: int, role: string, pid: int|null, iat: int}|null
     */
    public function currentClaims(): ?array
    {
        try {
            return $this->resolveClaims();
        } catch (\Throwable $e) {
            error_log(sprintf('[SCS] AuthContextService failed: %s', $e->getMessage()));

            return null;
        }
    }

    /** @return array{sub: int, role: string, pid: int|null, iat: int}|null */
    private function resolveClaims(): ?array
    {
        $token = $_COOKIE['scs_token'] ?? null;
        if (!is_string($token) || $token === '') {
            return null;
        }

        $claims = $this->jwtService->parse($token);
        if ($claims === null) {
            return null;
        }

        return match ($claims['role']) {
            Role::Admin->value  => $this->validateAdmin($claims),
            Role::Member->value => $this->validateMember($claims),
            default             => null,
        };
    }

    /**
     * @param array{sub: int, role: string, pid: int|null, iat: int} $claims
     * @return array{sub: int, role: string, pid: int|null, iat: int}|null
     */
    private function validateAdmin(array $claims): ?array
    {
        $admin = $this->adminRepository->findById($claims['sub']);
        if ($admin === null || $admin->status !== AdminStatus::Active) {
            return null;
        }

        return $this->isTokenStillValid($claims['iat'], $admin->token_valid_after) ? $claims : null;
    }

    /**
     * @param array{sub: int, role: string, pid: int|null, iat: int} $claims
     * @return array{sub: int, role: string, pid: int|null, iat: int}|null
     */
    private function validateMember(array $claims): ?array
    {
        $member = $this->memberRepository->findById($claims['sub']);
        if ($member === null || $member->status !== MemberStatus::Active) {
            return null;
        }

        return $this->isTokenStillValid($claims['iat'], $member->token_valid_after) ? $claims : null;
    }

    private function isTokenStillValid(int $iat, ?\DateTimeImmutable $tokenValidAfter): bool
    {
        return $tokenValidAfter === null || $iat >= $tokenValidAfter->getTimestamp();
    }
}
