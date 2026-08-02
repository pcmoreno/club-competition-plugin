<?php

declare(strict_types=1);

namespace SCS\Entity;

use SCS\Entity\Enum\PairingSystem;
use SCS\Entity\Enum\SeasonStatus;
use SCS\Entity\Enum\TimeControl;

class Season
{
    /**
     * @param array<string,mixed>|null $pairing_settings
     * @param array<string,mixed>|null $scoring_settings
     * @param array<string,mixed>|null $display_settings
     */
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
        public readonly ?array $pairing_settings = null,
        public readonly ?array $scoring_settings = null,
        public readonly ?array $display_settings = null,
        // The tempo this tournament is played at; its games inherit it.
        public readonly TimeControl $time_control = TimeControl::Classical,
    ) {
    }
}
