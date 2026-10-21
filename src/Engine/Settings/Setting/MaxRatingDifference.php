<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * The widest rating gap two opponents may have, in points.
 *
 * Measured on the rating each player was enrolled with, not whatever their
 * rating has become since — the tournament is judged against the field it
 * started with. Zero is unlimited.
 *
 * Alone among the pairing settings this is a bound rather than a preference:
 * a candidate outside it is removed from consideration instead of ranked below
 * the others, so a narrow value can leave a field that cannot be paired at all.
 */
final class MaxRatingDifference implements SettingInterface
{
    public const KEY = 'maxRatingDifference';

    public const DEFAULT = 0;

    public const MAX = 3000;

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Maximum rating difference',
            'type'    => FieldType::Number->value,
            'hint'    => 'The widest rating gap allowed between two opponents, counted on the rating they enrolled with. Zero means no limit. Unlike the other settings this one refuses a pairing rather than preferring against it, so a small value can leave players with nobody to play.',
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
