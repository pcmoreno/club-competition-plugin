<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;
use SCS\Entity\Enum\ValuationMethod;

/**
 * How much value separates one rung from the next.
 *
 * Only used by the stepped valuation methods. Position range ignores it and
 * derives its own step from the top and bottom values, since there the spread
 * is fixed and the field size decides the rest.
 */
final class ValueStep implements SettingInterface
{
    public const KEY = 'valueStep';

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
            'label'   => 'Value step',
            'type'    => FieldType::Number->value,
            'hint'    => 'How much a player’s value drops per rung.',
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
