<?php

declare(strict_types=1);

namespace SCS\Entity;

class SeasonPlayer
{
    public function __construct(
        public readonly int $id,
        public readonly int $season_id,
        public readonly int $player_id,
        public readonly ?string $category,
        public readonly int $elo_rating,
        public readonly \DateTimeImmutable $enrolled_at,
        public readonly bool $default_absent = false,
        // Team seasons only, and read from the season's line-up rather than stored here.
        public readonly ?int $board_number = null,
    ) {
    }
}
