<?php

declare(strict_types=1);

namespace SCS\Exception;

// Thrown by a pairing engine that could not place everyone. It carries the
// enrolment ids rather than names, which the engine has no way to resolve —
// the caller rewrites the message once it has looked them up.
class UnpairablePlayersException extends ConflictException
{
    /** @param list<int> $seasonPlayerIds */
    public function __construct(
        string $message,
        public readonly array $seasonPlayerIds,
    ) {
        parent::__construct($message);
    }
}
