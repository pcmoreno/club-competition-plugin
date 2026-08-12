<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;
use SCS\Entity\Enum\ValuationMethod;

/**
 * How many players share each rung before the value drops.
 *
 * One is a rung per player. Larger values group the field into bands worth the
 * same, which flattens the difference between neighbours and makes the ladder
 * less sensitive to a single result. Never zero — that would be a ladder with
 * no way down.
 */
final class ValueStepEvery implements SettingInterface
{
    public const KEY = 'valueStepEvery';

    public const DEFAULT = 1;

    public const MAX = 100;

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Players per rung',
            'type'    => FieldType::Number->value,
            'hint'    => 'How many players share a value before it steps down.',
            'default' => self::DEFAULT,
            'min'     => 1,
            'max'     => self::MAX,
            'step'    => 1,
            'enabledBy' => ['key' => Valuation::KEY, 'value' => [
                ValuationMethod::PositionFromTop->value,
                ValuationMethod::PositionFromBottom->value,
            ]],
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
