<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * How many decimals player values are rounded to, or null to leave them exact.
 *
 * This is not cosmetic. A ladder from 200 to 100 across fifty players steps by
 * a fraction, and rounding to whole numbers is what makes two adjacent players
 * differ by two points rather than 2.04 — which then propagates into every
 * score computed against them.
 */
final class ValueDecimals implements SettingInterface
{
    public const KEY = 'valueDecimals';

    public const DEFAULT = 0;

    public const MAX = 4;

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'       => self::KEY,
            'label'     => 'Round values to',
            'type'      => FieldType::Number->value,
            'nullable'  => true,
            'nullLabel' => 'Don’t round',
            'hint'      => 'Decimal places for player values. Whole numbers are usual and keep the ladder readable.',
            'default'   => self::DEFAULT,
            'min'       => 0,
            'max'       => self::MAX,
            'step'      => 1,
        ];
    }

    // Anything unusable means "don't round" — leaving a value exact can never
    // be wrong, where guessing a rounding would silently change every score.
    public function normalise(mixed $raw): ?int
    {
        if (!is_numeric($raw)) {
            return null;
        }

        return max(0, min((int)$raw, self::MAX));
    }
}
