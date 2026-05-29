<?php

declare(strict_types=1);

namespace SCS\Service;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

class JwtService
{
    private Configuration $config;

    public function __construct()
    {
        $secret = defined('SCS_JWT_SECRET') ? SCS_JWT_SECRET : 'dev-secret-change-in-production';
        $this->config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($secret));
    }

    public function issue(int $subject, string $role): string
    {
        $now = new \DateTimeImmutable();

        return $this->config->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify('+1 day'))
            ->withClaim('sub', $subject)
            ->withClaim('role', $role)
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
                new \Lcobucci\JWT\Validation\Constraint\SignedWith(
                    $this->config->signer(),
                    $this->config->signingKey()
                )
            );

            $expiry = $token->claims()->get('exp');
            if ($expiry instanceof \DateTimeImmutable && $expiry < new \DateTimeImmutable()) {
                return null;
            }

            return [
                'sub'  => (int)$token->claims()->get('sub'),
                'role' => (string)$token->claims()->get('role'),
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
