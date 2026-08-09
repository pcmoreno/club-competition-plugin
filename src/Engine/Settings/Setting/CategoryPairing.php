<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\CategoryPairingMode;
use SCS\Entity\Enum\FieldType;

/**
 * How far across the categories a pairing may reach.
 *
 * Two parts: whether the limit applies at all, and how many categories wide it
 * is. One means own category or the next one either way, which is what the club
 * plays; two would let an A meet a C in a three-category season, which is the
 * same as no limit there but means something in a season with five.
 *
 * A player with no category is never constrained — there is no distance to
 * measure from, and categories are optional per season.
 */
final class CategoryPairing implements SettingInterface
{
    public const KEY = 'categoryPairing';

    public const DISTANCE_KEY = 'categoryDistance';

    public const DEFAULT_DISTANCE = 1;

    public const MAX_DISTANCE = 20;

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Pairing across categories',
            'type'    => FieldType::Select->value,
            'hint'    => 'Whether a player may be paired against someone from a distant category.',
            'default' => CategoryPairingMode::Adjacent->value,
            'options' => array_map(
                static fn (CategoryPairingMode $mode) => ['value' => $mode->value, 'label' => $mode->label()],
                CategoryPairingMode::cases()
            ),
        ];
    }

    public function normalise(mixed $raw): CategoryPairingMode
    {
        return CategoryPairingMode::tryFrom(is_string($raw) ? $raw : '') ?? CategoryPairingMode::Adjacent;
    }

    /** @return array<string,mixed> */
    public function distanceField(): array
    {
        return [
            'key'     => self::DISTANCE_KEY,
            'label'   => '…how many apart',
            'type'    => FieldType::Number->value,
            'hint'    => 'One means own category or the next one either way.',
            'default' => self::DEFAULT_DISTANCE,
            'min'     => 1,
            'max'     => self::MAX_DISTANCE,
            'step'    => 1,
            // Reads as a continuation of the limit above, and means nothing
            // without it, so it sits alongside and greys out when there is no
            // limit to be a number of.
            'inline'    => true,
            'enabledBy' => ['key' => self::KEY, 'value' => CategoryPairingMode::Adjacent->value],
        ];
    }

    public function normaliseDistance(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            return self::DEFAULT_DISTANCE;
        }

        return max(1, min((int)$raw, self::MAX_DISTANCE));
    }
}
