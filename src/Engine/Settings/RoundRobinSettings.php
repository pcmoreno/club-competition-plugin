<?php

declare(strict_types=1);

namespace SCS\Engine\Settings;

use SCS\Entity\Enum\GroupingMode;
use SCS\Entity\Enum\SeedingMethod;

/**
 * The knobs the round-robin generator reads, whether or not the tournament is
 * divided into groups — so the engine takes one type instead of a union of the
 * two settings classes.
 */
interface RoundRobinSettings extends TournamentPairingSettings
{
    public function legs(): int;

    public function seeding(): SeedingMethod;

    public function alternateColoursPerLeg(): bool;

    // Null when the whole field is one undivided round-robin.
    public function grouping(): ?GroupingMode;
}
