<?php

declare(strict_types=1);

namespace SCS\Entity;

use SCS\Entity\Enum\Gender;

class Player
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $knsb_id,
        public readonly ?int $knsb_elo,
        public readonly ?Gender $gender,
        public readonly ?\DateTimeImmutable $date_of_birth,
        public readonly bool $active,
        public readonly \DateTimeImmutable $created_at,
    ) {
    }
}
