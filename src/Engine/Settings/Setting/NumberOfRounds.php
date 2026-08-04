<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * How many rounds a tournament runs, or null for as many as it takes.
 *
 * Null is a real value, not a missing one: the club's internal competition just
 * adds a round every Tuesday and stops when the season does, so "unlimited" is
 * the default and every tournament that predates this setting reads as one.
 *
 * Only systems that let the organiser *choose* compose this. A round-robin's
 * round count follows from the roster and the number of legs, and a knockout's
 * from the field size — for those the engine computes it and there is nothing
 * to ask.
 */
final class NumberOfRounds implements SettingInterface
{
    public const KEY = 'numberOfRounds';

    // rounds.round_number is TINYINT UNSIGNED, so this is the hard ceiling
    // whatever an admin types.
    public const MAX = 255;

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'       => self::KEY,
            'label'     => 'Number of rounds',
            'type'      => FieldType::Number->value,
            // Null is selectable in its own right, so the form pairs the input
            // with a toggle rather than treating an empty box as unset.
            'nullable'  => true,
            'nullLabel' => 'Unlimited',
            'hint'      => 'Unlimited keeps adding rounds for as long as the tournament runs. With a number set, no round can be added past it.',
            'default'   => null,
            'min'       => 1,
            'max'       => self::MAX,
            'step'      => 1,
        ];
    }

    /**
     * Anything that isn't a whole number of rounds means unlimited — that's the
     * safe reading, since it leaves the tournament running rather than sealing
     * it at a number nobody chose. An over-large value clamps instead, because
     * there the intent (a fixed count) is clear and only the size is wrong.
     */
    public function normalise(mixed $raw): ?int
    {
        if (!is_numeric($raw)) {
            return null;
        }

        $value = (int)$raw;

        return $value < 1 ? null : min($value, self::MAX);
    }
}
