<?php

declare(strict_types=1);

namespace SCS\Security;

/**
 * `is_ssl()` alone reads the PHP-facing hop, which is plain HTTP behind a
 * TLS-terminating proxy (e.g. SiteGround's front-end) — so it misreports
 * HTTPS requests as insecure and cookies lose the Secure flag. No trusted-
 * proxy allowlist is configured, so this only adds X-Forwarded-Proto as a
 * second signal; it doesn't change is_ssl()'s own trust model.
 */
class RequestContext
{
    public static function isSecure(): bool
    {
        return is_ssl() || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
