<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;
use SCS\Entity\Enum\GroupingMode;

/**
 * How a grouped round-robin splits its field.
 *
 * Categories are already a per-season concept the roster is enrolled against,
 * so they are the mode that needs nothing new; the rating splits are for a large
 * open field that has no categories to divide it.
 */
final class Grouping implements SettingInterface
{
    public const KEY = 'grouping';

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'key'     => self::KEY,
            'label'   => 'Groups',
            'type'    => FieldType::Select->value,
            'hint'    => 'Each group plays its own round-robin. Every group’s games sit in the same rounds.',
            'default' => GroupingMode::Categories->value,
            'options' => array_map(
                static fn (GroupingMode $mode) => [
                    'value'       => $mode->value,
                    'label'       => $mode->label(),
                    'implemented' => $mode->isImplemented(),
                ],
                GroupingMode::cases()
            ),
        ];
    }

    // An unimplemented mode coerces back to categories on read, the same defence
    // the scoring tiebreaks use: honouring one would leave the season configured
    // for a split the generator can't produce.
    public function normalise(mixed $raw): GroupingMode
    {
        $mode = GroupingMode::tryFrom(is_string($raw) ? $raw : '');

        return $mode !== null && $mode->isImplemented() ? $mode : GroupingMode::Categories;
    }
}
