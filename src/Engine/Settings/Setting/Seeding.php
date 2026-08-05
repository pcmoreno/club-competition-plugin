<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;
use SCS\Entity\Enum\SeedingMethod;

/**
 * Which order the field is numbered in before the schedule is built.
 *
 * Rating order is what a club expects and gives the top seeds a sane colour
 * spread; drawing lots is what FIDE prescribes for a closed event. The draw
 * needs no stored seed — the fixture is persisted as rounds and games, so it is
 * its own record.
 */
final class Seeding implements SettingInterface
{
    public const KEY = 'seeding';

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Seeding',
            'type'    => FieldType::Select->value,
            'hint'    => 'The order players are numbered in. The schedule follows from those numbers.',
            'default' => SeedingMethod::Rating->value,
            'options' => array_map(
                static fn (SeedingMethod $method) => ['value' => $method->value, 'label' => $method->label()],
                SeedingMethod::cases()
            ),
        ];
    }

    public function normalise(mixed $raw): SeedingMethod
    {
        return SeedingMethod::tryFrom(is_string($raw) ? $raw : '') ?? SeedingMethod::Rating;
    }
}
