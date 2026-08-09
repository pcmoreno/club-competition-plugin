<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * What the bottom of the ranking is worth.
 *
 * Not validated against the top value here — a Setting can't see its siblings —
 * so the ladder itself orders the pair, and a bottom above the top simply
 * builds an inverted ladder rather than failing.
 */
final class BottomValue implements SettingInterface
{
    public const KEY = 'bottomValue';

    public const DEFAULT = 100;

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Bottom value',
            'type'    => FieldType::Number->value,
            'hint'    => 'What a win against the player at the bottom of the ranking is worth.',
            'default' => self::DEFAULT,
            'min'     => 0,
            'max'     => TopValue::MAX,
            'step'    => 1,
        ];
    }

    public function normalise(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            return self::DEFAULT;
        }

        return max(0, min((int)$raw, TopValue::MAX));
    }
}
