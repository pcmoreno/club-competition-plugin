<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * How many rounds the Aalsmeer bonus holds at full strength before it decays.
 *
 * With a bonus of 5 and an offset of 3: rounds 1-3 score five extra helpings of
 * own value, then 4, 3, 2, 1, and nothing from round 8 on. Meaningless on its
 * own — AalsmeerRounds at zero switches the whole mechanism off.
 */
final class AalsmeerOffset implements SettingInterface
{
    public const KEY = 'aalsmeerOffset';

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
            'label'   => 'Aalsmeer full-strength rounds',
            'type'    => FieldType::Number->value,
            'hint'    => 'How many opening rounds keep the full bonus before it starts decaying.',
            'default' => 0,
            'min'     => 0,
            'max'     => self::MAX,
            'step'    => 1,
        ];
    }

    public function normalise(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            return 0;
        }

        return max(0, min((int)$raw, self::MAX));
    }
}
