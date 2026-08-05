<?php

declare(strict_types=1);

namespace SCS\Engine\Settings;

use SCS\Engine\Settings\Setting\AlternateColoursPerLeg;
use SCS\Engine\Settings\Setting\Grouping;
use SCS\Engine\Settings\Setting\Legs;
use SCS\Engine\Settings\Setting\Seeding;
use SCS\Entity\Enum\GroupingMode;
use SCS\Entity\Enum\SeedingMethod;

/**
 * The same round-robin, run once per group, plus how the field is divided.
 *
 * Legs and seeding apply within each group — the groups are separate
 * tournaments that happen to share a round schedule and a standings page.
 */
final class RoundRobinGroupsPairingSettings implements TournamentPairingSettings
{
    public function __construct(
        public readonly int $legs = 1,
        public readonly SeedingMethod $seeding = SeedingMethod::Rating,
        public readonly bool $alternateColoursPerLeg = AlternateColoursPerLeg::DEFAULT,
        public readonly GroupingMode $grouping = GroupingMode::Categories,
    ) {
    }

    /** @return array<string,mixed> */
    public function getSettings(): array
    {
        return [
            Legs::KEY                   => $this->legs,
            Seeding::KEY                => $this->seeding->value,
            AlternateColoursPerLeg::KEY => $this->alternateColoursPerLeg,
            Grouping::KEY               => $this->grouping->value,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function getSettingsFields(): array
    {
        return [
            (new Grouping())->field(),
            (new Legs())->field(),
            (new Seeding())->field(),
            (new AlternateColoursPerLeg())->field(),
        ];
    }

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values): static
    {
        return new self(
            (new Legs())->normalise($values[Legs::KEY] ?? null),
            (new Seeding())->normalise($values[Seeding::KEY] ?? null),
            (new AlternateColoursPerLeg())->normalise($values[AlternateColoursPerLeg::KEY] ?? null),
            (new Grouping())->normalise($values[Grouping::KEY] ?? null),
        );
    }
}
