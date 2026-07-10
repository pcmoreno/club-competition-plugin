<?php

declare(strict_types=1);

namespace SCS\Services;

/**
 * Fixed-window attempt counter backed by WP transients (wp_options fallback,
 * object cache when configured — works unmodified on shared hosting). Keys
 * are caller-defined so the same service can throttle distinct scopes
 * (login by IP, login by account, password-reset by IP, ...) independently.
 */
class RateLimiterService
{
    private const TRANSIENT_PREFIX = 'scs_rl_';

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return $this->attempts($key) >= $maxAttempts;
    }

    // Non-atomic read-modify-write: concurrent hits on one key can lose
    // increments. Accepted — each attempt still costs bcrypt and the caps hold;
    // an atomic wp_cache_incr() needs an object cache shared hosting may lack.
    public function hit(string $key, int $decaySeconds): void
    {
        set_transient($this->transientKey($key), $this->attempts($key) + 1, $decaySeconds);
    }

    public function clear(string $key): void
    {
        delete_transient($this->transientKey($key));
    }

    private function attempts(string $key): int
    {
        return (int)get_transient($this->transientKey($key));
    }

    /**
     * Hashed so caller-supplied identifiers (emails, IPs) never appear in
     * plain text as a wp_options option_name, and so the key always fits the
     * column's length limit regardless of input.
     */
    private function transientKey(string $key): string
    {
        return self::TRANSIENT_PREFIX . hash('sha256', $key);
    }
}
