<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;
use SCS\Entity\Enum\InitialValueOrder;

/**
 * What orders the ladder in round 1, when there are no scores to order it by.
 *
 * Every later round assigns values from the score ranking, so this only ever
 * decides the opening rungs — but those rungs price round 1's games, and a
 * Keizer score carries forward all season.
 */
final class InitialOrder implements SettingInterface
{
    public const KEY = 'initialValueOrder';

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Opening value order',
            'type'    => FieldType::Select->value,
            'hint'    => 'Round one has no scores yet, so the first set of values follows this order.',
            'default' => InitialValueOrder::Rating->value,
            'options' => array_map(
                static fn (InitialValueOrder $order) => ['value' => $order->value, 'label' => $order->label()],
                InitialValueOrder::cases()
            ),
        ];
    }

    public function normalise(mixed $raw): InitialValueOrder
    {
        return InitialValueOrder::tryFrom(is_string($raw) ? $raw : '') ?? InitialValueOrder::Rating;
    }
}
