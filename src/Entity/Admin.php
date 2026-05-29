<?php

declare(strict_types=1);

namespace SCS\Entity;

use SCS\Entity\Enum\AdminStatus;

class Admin
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly string $password_hash,
        public readonly AdminStatus $status,
        public readonly \DateTimeImmutable $created_at,
    ) {
    }
}
