<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * How many games in a row a player may have the same colour.
 *
 * Distinct from the overall imbalance: someone can sit at zero difference and
 * still have had three whites running, which is the thing a member actually
 * notices and mentions.
 */
final class MaxSameColourRun implements SettingInterface
{
    public const KEY = 'maxConsecutiveSameColor';

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
            'label'   => 'Most games in a row with one colour',
            'type'    => FieldType::Number->value,
            'hint'    => 'How many games in a row a player may have the same colour. Below 2 leaves too little room to pair.',
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
