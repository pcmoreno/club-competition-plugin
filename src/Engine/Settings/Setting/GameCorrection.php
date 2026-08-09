<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * The GC constant in the damped pairing percentage:
 * `(wins + ½ draws + SC) / (games + GC)`.
 *
 * The imaginary games a player is credited with, which is what makes a small
 * sample pull towards the middle instead of the extremes. Larger values damp
 * harder and take longer for real results to overcome.
 */
final class GameCorrection implements SettingInterface
{
    public const KEY = 'gameCorrection';

    public const DEFAULT = 6;

    public const MAX = 200;

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Game correction',
            'type'    => FieldType::Number->value,
            'hint'    => 'Imaginary games added before the percentage is worked out. Higher damps newcomers harder.',
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
