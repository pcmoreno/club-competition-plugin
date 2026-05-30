<?php

declare(strict_types=1);

namespace SCS\Entity;

use SCS\Entity\Enum\PairingSystem;
use SCS\Entity\Enum\SeasonStatus;

class Season
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $location,
        public readonly ?\DateTimeImmutable $start_date,
        public readonly ?\DateTimeImmutable $end_date,
        public readonly PairingSystem $pairing_system,
        public readonly SeasonStatus $status,
        public readonly array $categories,
        public readonly \DateTimeImmutable $created_at,
    ) {
    }
}
