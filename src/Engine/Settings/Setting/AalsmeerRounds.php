<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * The Aalsmeer bonus: how many extra times a player's own value is added early
 * in the season, before the bonus decays to nothing.
 *
 * It exists to stop a strong player who loses their first game falling through
 * the whole field, which in a system where opponents' values price your score
 * takes weeks to climb back from. Zero — the default, and what the club runs —
 * turns it off entirely.
 *
 * Paired with AalsmeerOffset: the bonus holds at this value for the first
 * `offset` rounds, then drops by one per round until it reaches zero.
 */
final class AalsmeerRounds implements SettingInterface
{
    public const KEY = 'aalsmeerRounds';

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
            'label'   => 'Aalsmeer bonus',
            'type'    => FieldType::Number->value,
            'hint'    => 'Extra helpings of a player’s own value in the opening rounds, decaying by one a round. Zero switches it off.',
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
