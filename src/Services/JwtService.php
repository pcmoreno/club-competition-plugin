<?php

declare(strict_types=1);

namespace SCS\Services;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Psr\Clock\ClockInterface;
use SCS\Entity\Enum\Role;

class JwtService
{
    public const TOKEN_TTL_SECONDS = 86400;

    /** Option holding the auto-generated signing key when no constant is set. */
    private const SECRET_OPTION = 'scs_jwt_secret';

    /** Placeholder value that must never be accepted as a real secret. */
    private const PLACEHOLDER = 'dev-secret-change-in-production';

    private Configuration $config;
    private ClockInterface $clock;

    public function __construct()
    {
        $this->config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(self::resolveSecret()));
        $this->clock  = new class () implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable();
            }
        };
    }

    /**
     * The JWT signing key.
     *
     * Prefer an explicit SCS_JWT_SECRET constant (defined in wp-config.php) so
     * each environment can control its own key. When none is set, fall back to
     * a strong secret generated once and stored in the options table — so a
     * fresh install authenticates with no manual config, and the key is unique
     * per site and never shipped in the plugin artifact or committed to git.
     */
    private static function resolveSecret(): string
    {
        if (defined('SCS_JWT_SECRET') && SCS_JWT_SECRET !== '' && SCS_JWT_SECRET !== self::PLACEHOLDER) {
            return SCS_JWT_SECRET;
        }

        return self::ensureGeneratedSecret();
    }

    /**
     * Return the stored auto-generated secret, creating it on first use.
     * Idempotent: only generates when the option is missing/empty.
     */
    public static function ensureGeneratedSecret(): string
    {
        $secret = get_option(self::SECRET_OPTION, '');
        if (!is_string($secret) || $secret === '') {
            $secret = bin2hex(random_bytes(32));
            // Autoloaded — read on most authenticated requests. add_option is a
            // no-op if another request already created it; re-read to be safe.
            add_option(self::SECRET_OPTION, $secret, '', true);
            $stored = get_option(self::SECRET_OPTION, $secret);
            $secret = is_string($stored) && $stored !== '' ? $stored : $secret;
        }

        return $secret;
    }

    public function issue(int $subject, Role $role, ?int $playerId = null): string
    {
        $now = $this->clock->now();

        $builder = $this->config->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify('+' . self::TOKEN_TTL_SECONDS . ' seconds'))
            // `sub` is a registered claim — lcobucci rejects withClaim() for it,
            // so use the dedicated relatedTo() builder method.
            ->relatedTo((string)$subject)
            ->withClaim('role', $role->value);

        // Members carry their player id so the frontend can identify "you"
        // (e.g. highlight your own game). Admins have no associated player.
        if ($playerId !== null) {
            $builder = $builder->withClaim('pid', $playerId);
        }

        return $builder
            ->getToken($this->config->signer(), $this->config->signingKey())
            ->toString();
    }

    /** @return array{sub: int, role: string, pid: int|null}|null */
    public function parse(string $tokenString): ?array
    {
        try {
            $token = $this->config->parser()->parse($tokenString);

            $this->config->validator()->assert(
                $token,
                new SignedWith($this->config->signer(), $this->config->signingKey()),
                new LooseValidAt($this->clock),
            );

            $claims = $token->claims();

            return [
                'sub'  => (int)$claims->get('sub'),
                'role' => (string)$claims->get('role'),
                'pid'  => $claims->has('pid') ? (int)$claims->get('pid') : null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
