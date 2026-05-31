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

    private Configuration $config;
    private ClockInterface $clock;

    public function __construct()
    {
        if (!defined('SCS_JWT_SECRET') || SCS_JWT_SECRET === '' || SCS_JWT_SECRET === 'dev-secret-change-in-production') {
            throw new \RuntimeException('SCS_JWT_SECRET must be defined in wp-config.php with a strong secret.');
        }

        $this->config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(SCS_JWT_SECRET));
        $this->clock  = new class () implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable();
            }
        };
    }

    public function issue(int $subject, Role $role): string
    {
        $now = $this->clock->now();

        return $this->config->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify('+' . self::TOKEN_TTL_SECONDS . ' seconds'))
            // `sub` is a registered claim — lcobucci rejects withClaim() for it,
            // so use the dedicated relatedTo() builder method.
            ->relatedTo((string)$subject)
            ->withClaim('role', $role->value)
            ->getToken($this->config->signer(), $this->config->signingKey())
            ->toString();
    }

    /** @return array{sub: int, role: string}|null */
    public function parse(string $tokenString): ?array
    {
        try {
            $token = $this->config->parser()->parse($tokenString);

            $this->config->validator()->assert(
                $token,
                new SignedWith($this->config->signer(), $this->config->signingKey()),
                new LooseValidAt($this->clock),
            );

            return [
                'sub'  => (int)$token->claims()->get('sub'),
                'role' => (string)$token->claims()->get('role'),
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
