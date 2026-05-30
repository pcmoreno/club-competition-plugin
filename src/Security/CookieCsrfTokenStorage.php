<?php

declare(strict_types=1);

namespace SCS\Security;

use SCS\Services\JwtService;
use Symfony\Component\Security\Csrf\TokenStorage\TokenStorageInterface;

class CookieCsrfTokenStorage implements TokenStorageInterface
{
    private const COOKIE_NAME = 'scs_csrf';

    public function getToken(string $tokenId): string
    {
        if (!isset($_COOKIE[self::COOKIE_NAME])) {
            throw new \RuntimeException('CSRF token not found.');
        }

        return $_COOKIE[self::COOKIE_NAME];
    }

    public function setToken(string $tokenId, string $value): void
    {
        $_COOKIE[self::COOKIE_NAME] = $value;

        setcookie(self::COOKIE_NAME, $value, [
            'expires'  => time() + JwtService::TOKEN_TTL_SECONDS,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    public function removeToken(string $tokenId): ?string
    {
        $value = $_COOKIE[self::COOKIE_NAME] ?? null;

        unset($_COOKIE[self::COOKIE_NAME]);

        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        return $value;
    }

    public function hasToken(string $tokenId): bool
    {
        return isset($_COOKIE[self::COOKIE_NAME]);
    }
}
