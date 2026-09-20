<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * How many games a player must play against others before meeting the same
 * opponent again.
 *
 * The rematch window measured in games rather than rounds, so a round a player
 * missed doesn't bring their rematch closer. Which of the two binds depends on
 * how often someone turns up: a weekly player reaches the games count first, a
 * sporadic one is still short of it long after the rounds have passed.
 *
 * A preference like the window it mirrors. Zero switches it off.
 */
final class PlaysBetweenPairings implements SettingInterface
{
    public const KEY = 'playsBetweenSamePairing';

    public const DEFAULT = 5;

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
            'label'   => 'Games between rematches',
            'type'    => FieldType::Number->value,
            'hint'    => 'How many games each player must play against others before the two may be paired again. Rounds they missed don\'t count, which is what makes this differ from the rounds window.',
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
