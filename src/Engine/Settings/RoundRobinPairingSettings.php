<?php

declare(strict_types=1);

namespace SCS\Engine\Settings;

use SCS\Engine\Settings\Setting\AlternateColoursPerLeg;
use SCS\Engine\Settings\Setting\Legs;
use SCS\Engine\Settings\Setting\Seeding;
use SCS\Entity\Enum\SeedingMethod;

/**
 * Everyone plays everyone, so the fixture has only two things to decide: how the
 * field is numbered and how many times it goes round.
 *
 * Note what isn't here. There is no round count — it is legs × (N-1) for an even
 * field and legs × N for an odd one, so asking would only invite a number that
 * contradicts the roster. And there is no bye value: the odd player out gets a
 * pairing bye, which is a reserved key in the scoring settings and priced there.
 */
final class RoundRobinPairingSettings implements TournamentPairingSettings
{
    public function __construct(
        private readonly int $legsValue = 1,
        private readonly SeedingMethod $seedingValue = SeedingMethod::Rating,
        private readonly bool $alternateColours = AlternateColoursPerLeg::DEFAULT,
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

    /** @return array<string,mixed> */
    public function getSettings(): array
    {
        return [
            Legs::KEY                   => $this->legsValue,
            Seeding::KEY                => $this->seedingValue->value,
            AlternateColoursPerLeg::KEY => $this->alternateColours,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function getSettingsFields(): array
    {
        return [
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
        );
    }
}
