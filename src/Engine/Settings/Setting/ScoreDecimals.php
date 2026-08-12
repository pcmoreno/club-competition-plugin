<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * How many decimals a total score is rounded to, or null to leave it exact.
 *
 * Separate from the value rounding because they round different things: values
 * are rounded once when the ladder is built, scores after everything has been
 * summed. A draw against a 167-value opponent contributes 83.5 either way.
 */
final class ScoreDecimals implements SettingInterface
{
    public const KEY = 'scoreDecimals';

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
            'label'     => 'Round scores to',
            'type'      => FieldType::Number->value,
            'nullable'  => true,
            'nullLabel' => 'Don’t round',
            'hint'      => 'Decimal places for the published score.',
            'default'   => self::DEFAULT,
            'min'       => 0,
            'max'       => self::MAX,
            'step'      => 1,
        ];
    }

    public function normalise(mixed $raw): ?int
    {
        if (!is_numeric($raw)) {
            return null;
        }

        return max(0, min((int)$raw, self::MAX));
    }
}
