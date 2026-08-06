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
final class RoundRobinGroupsPairingSettings implements RoundRobinSettings
{
    public function __construct(
        private readonly int $legsValue = 1,
        private readonly SeedingMethod $seedingValue = SeedingMethod::Rating,
        private readonly bool $alternateColours = AlternateColoursPerLeg::DEFAULT,
        private readonly GroupingMode $groupingValue = GroupingMode::Categories,
    ) {
    }

    public function legs(): int
    {
        return $this->legsValue;
    }

    public function seeding(): SeedingMethod
    {
        return $this->seedingValue;
    }

    public function alternateColoursPerLeg(): bool
    {
        return $this->alternateColours;
    }

    public function grouping(): GroupingMode
    {
        return $this->groupingValue;
    }

    /** @return array<string,mixed> */
    public function getSettings(): array
    {
        return [
            Legs::KEY                   => $this->legsValue,
            Seeding::KEY                => $this->seedingValue->value,
            AlternateColoursPerLeg::KEY => $this->alternateColours,
            Grouping::KEY               => $this->groupingValue->value,
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
