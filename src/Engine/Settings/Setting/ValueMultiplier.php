<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * A flat multiplier applied to every value once the ladder is built.
 *
 * Scales the whole scoreboard without changing anything about its shape — the
 * gaps between players stay proportionally identical. Useful only to make the
 * published numbers bigger or smaller to taste.
 */
final class ValueMultiplier implements SettingInterface
{
    public const KEY = 'valueMultiplier';

    public const DEFAULT = 1;

    public const MAX = 1000;

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Multiply values by',
            'type'    => FieldType::Number->value,
            'hint'    => 'Scales every value. Changes the size of the published numbers, not the standings.',
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
