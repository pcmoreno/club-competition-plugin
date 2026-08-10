<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * How far a player's colour count may drift from even.
 *
 * Alternating from the last game keeps colours roughly balanced on its own, but
 * byes, absences and awkward pairings let a difference build up unnoticed. This
 * is the cap that pulls it back: at the limit, a player's colour stops being
 * negotiable and the pairing works around them.
 */
final class MaxColourDifference implements SettingInterface
{
    public const KEY = 'maxColorDifference';

    public const DEFAULT = 2;

    public const MAX = 20;

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Most colour imbalance',
            'type'    => FieldType::Number->value,
            'hint'    => 'How many more games of one colour than the other a player may accumulate. Below 2 leaves too little room to pair.',
            'default' => self::DEFAULT,
            'min'     => 1,
            'max'     => self::MAX,
            'step'    => 1,
        ];
    }

    public function normalise(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            return self::DEFAULT;
        }

        return max(1, min((int)$raw, self::MAX));
    }
}
