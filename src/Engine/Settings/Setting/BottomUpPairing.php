<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;

/**
 * Whether the pairing may work from the bottom of the ranking as well as the top.
 *
 * Off, it runs straight down the list and whoever is left at the end takes what
 * remains — which tends to leave the weakest players with the most arbitrary
 * board of the evening. On, both ends are paired towards the middle so the
 * awkward remainder lands among players of similar strength.
 */
final class BottomUpPairing implements SettingInterface
{
    public const KEY = 'bottomUpPairing';

    public const DEFAULT = true;

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Pair from both ends',
            'type'    => FieldType::Toggle->value,
            'hint'    => 'Work in from the top and the bottom, so the hardest pairings to make land in the middle.',
            'default' => self::DEFAULT,
        ];
    }

    public function normalise(mixed $raw): bool
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? self::DEFAULT;
    }
}
