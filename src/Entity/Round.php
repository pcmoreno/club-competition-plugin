<?php

declare(strict_types=1);

namespace SCS\Entity;

use SCS\Entity\Enum\RoundStatus;

class Round
{
    public function __construct(
        public readonly int $id,
        public readonly int $season_id,
        public readonly int $round_number,
        public readonly ?\DateTimeImmutable $date,
        public readonly RoundStatus $status,
        public readonly \DateTimeImmutable $created_at,
    ) {
    }
}
