<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * How many players the pairing may reach past to find a better colour.
 *
 * The trade is direct: too low and the colour-aware algorithm can't do
 * anything, too high and it starts pairing people of visibly different
 * strength to fix a colour nobody minds. Ignored entirely by the standard
 * algorithm, which never skips.
 */
final class SkipLimit implements SettingInterface
{
    public const KEY = 'limit';

    public const DEFAULT = 5;

    public const MAX = 50;

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Look-ahead limit',
            'type'    => FieldType::Number->value,
            'hint'    => 'How far down the ranking the colour-aware algorithm may reach for a better colour. The standard algorithm never reaches, and a player already at a colour limit is paired around whatever this says.',
            'default' => self::DEFAULT,
            'min'     => 0,
            'max'     => self::MAX,
            'step'    => 1,
        ];
    }

    public function normalise(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            return self::DEFAULT;
        }

        return max(0, min((int)$raw, self::MAX));
    }
}
