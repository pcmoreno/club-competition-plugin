<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;
use SCS\Entity\Enum\ValuationMethod;

/**
 * What the top of the ranking is worth.
 *
 * With the bottom value it fixes the whole ladder, since Position range spreads
 * everyone else linearly between the two. The spread matters more than the
 * numbers: a narrow one makes every opponent worth much the same and the
 * tournament drifts towards plain points, a wide one makes beating the leader
 * decisive.
 */
final class TopValue implements SettingInterface
{
    public const KEY = 'topValue';

    public const DEFAULT = 200;

    public const MAX = 10000;

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Top value',
            'type'    => FieldType::Number->value,
            'hint'    => 'What a win against the player at the top of the ranking is worth.',
            'default' => self::DEFAULT,
            'min'     => 1,
            'max'     => self::MAX,
            'step'    => 1,
            'enabledBy' => ['key' => Valuation::KEY, 'value' => [
                ValuationMethod::PositionRange->value,
                ValuationMethod::PositionFromTop->value,
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
