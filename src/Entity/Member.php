<?php

declare(strict_types=1);

namespace SCS\Entity;

use SCS\Entity\Enum\MemberStatus;

class Member
{
    public function __construct(
        public readonly int $id,
        public readonly int $player_id,
        public readonly string $email,
        public readonly ?string $password_hash,
        public readonly ?string $invite_token,
        public readonly ?\DateTimeImmutable $invite_expires_at,
        public readonly ?string $reset_token,
        public readonly ?\DateTimeImmutable $reset_expires_at,
        public readonly MemberStatus $status,
        public readonly \DateTimeImmutable $created_at,
    ) {
    }
}
