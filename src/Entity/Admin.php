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
        // Null until an invited admin follows their link and sets one.
        public readonly ?string $password_hash,
        public readonly AdminStatus $status,
        public readonly \DateTimeImmutable $created_at,
        public readonly ?string $invite_token = null,
        public readonly ?\DateTimeImmutable $invite_expires_at = null,
        public readonly ?\DateTimeImmutable $token_valid_after = null,
    ) {
    }
}
