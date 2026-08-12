<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * How many times the same two players may meet across a season.
 *
 * Like the rematch window this is a preference the pairing satisfies when it
 * can. The club's cap is four and their history reaches it exactly once in 444
 * games, which is about what a season this long produces.
 */
final class MaxRematches implements SettingInterface
{
    public const KEY = 'maxSamePairings';

    public const DEFAULT = 4;

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
            'label'   => 'Most meetings per pair',
            'type'    => FieldType::Number->value,
            'hint'    => 'How often the same two players may meet over the whole season.',
            'default' => self::DEFAULT,
            'min'     => 1,
            'max'     => self::MAX,
            'step'    => 1,
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
