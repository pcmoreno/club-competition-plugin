<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * The SC constant in the damped pairing percentage:
 * `(wins + ½ draws + SC) / (games + GC)`.
 *
 * It is the imaginary half-score a player starts with, so someone with one win
 * reads as 4/7 rather than 1/1 and gets an opponent of their own strength
 * instead of the leader. Only consulted when pairing on percentage.
 */
final class ScoreCorrection implements SettingInterface
{
    public const KEY = 'scoreCorrection';

    public const DEFAULT = 3;

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
            'label'   => 'Score correction',
            'type'    => FieldType::Number->value,
            'hint'    => 'Imaginary half-points added before the percentage is worked out. Must not exceed the game correction.',
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
