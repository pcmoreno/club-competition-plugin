<?php

declare(strict_types=1);

namespace SCS\Engine\Settings;

use SCS\Entity\Enum\FieldType;
use SCS\Entity\Enum\StandingsColumn;

// Which standings columns show, and in what order — universal, not system-specific.
final class StandingsDisplaySettings implements TournamentStandingsDisplaySettings
{
    public const DEFAULT_COLUMNS = [
        'position', 'name', 'games', 'wins', 'draws', 'losses', 'points', 'performance_rating', 'rating',
    ];

    /** @param list<string> $columns ordered StandingsColumn values */
    public function __construct(
        public readonly array $columns = self::DEFAULT_COLUMNS,
    ) {
    }

    /** @return array<string,mixed> */
    public function getSettings(): array
    {
        return ['columns' => $this->columns];
    }

    /** @return list<array<string,mixed>> */
    public function getSettingsFields(): array
    {
        return [
            [
                'group'   => 'columns',
                'type'    => FieldType::OrderedMultiSelect->value,
                'options' => array_map(
                    static fn (StandingsColumn $c) => ['value' => $c->value, 'label' => $c->label()],
                    StandingsColumn::cases()
                ),
                'default' => self::DEFAULT_COLUMNS,
            ],
        ];
    }

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values): static
    {
        $columns = isset($values['columns']) && is_array($values['columns'])
            ? array_values(array_filter(array_map(
                static fn ($v) => StandingsColumn::tryFrom((string)$v)?->value,
                $values['columns']
            )))
            : self::DEFAULT_COLUMNS;

        return new self($columns ?: self::DEFAULT_COLUMNS);
    }
}
