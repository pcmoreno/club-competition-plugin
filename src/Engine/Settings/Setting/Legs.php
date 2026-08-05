<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * How many times each pair meets.
 *
 * Two is the familiar double round-robin, but there is no reason to stop there:
 * a two-player round-robin is a match, and 20 legs of it is a 20-game match with
 * alternating colours — the same generator, no separate format.
 *
 * The cap here is flat because a Setting can't see the roster. What is actually
 * bounded is legs × field size, and only the generator knows both: 100 legs is
 * fine for two players and impossible for four. So this stops an admin typing
 * something absurd, and pairSchedule() rejects the combinations it can't build
 * with a message naming the real numbers.
 */
final class Legs implements SettingInterface
{
    public const KEY = 'legs';

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
            'label'   => 'Legs',
            'type'    => FieldType::Number->value,
            'hint'    => 'How many times each pair meets. One is a single round-robin, two the usual double. Two players over many legs is a match.',
            'default' => 1,
            'min'     => 1,
            'max'     => self::MAX,
            'step'    => 1,
        ];
    }

    // Unlike a round count, there is no "unset" reading of this — a tournament
    // where nobody meets isn't one — so anything unusable falls back to a single
    // leg rather than to null.
    public function normalise(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            return 1;
        }

        $value = (int)$raw;

        return $value < 1 ? 1 : min($value, self::MAX);
    }
}
