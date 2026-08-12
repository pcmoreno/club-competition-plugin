<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\FieldType;
use SCS\Entity\Enum\ScoringOutcome;
use SCS\Entity\Enum\ScoringSettingsGroup;

/**
 * What a win, draw and loss are worth.
 *
 * Shared by every scoring system, but consumed differently: standard scoring
 * adds the number to a total, Keizer multiplies it by the opponent's value.
 * Sevilla calls it Par for that reason — it is a coefficient, not a point.
 *
 * The defaults come from the composing settings class rather than living here,
 * since a system may price the same outcome differently.
 */
final class GameOutcomes implements SettingInterface
{
    public const KEY = 'gameOutcomes';

    /** @param array<string,float> $defaults ScoringOutcome value => points */
    public function __construct(private readonly array $defaults)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'group'  => ScoringSettingsGroup::GameOutcomes->value,
            'type'   => FieldType::Number->value,
            'fields' => array_map(
                fn (ScoringOutcome $outcome) => [
                    'key'     => $outcome->value,
                    'label'   => $outcome->label(),
                    'default' => $this->defaults[$outcome->value] ?? 0.0,
                    'min'     => 0,
                    'step'    => 0.5,
                ],
                ScoringOutcome::cases()
            ),
        ];
    }

    /**
     * A union rather than a rebuild: a stored value passes through untouched so
     * getSettings() round-trips exactly what was saved, and only the outcomes
     * the blob omits fall back.
     *
     * @return array<string,mixed>
     */
    // Nothing is ever worth less than nothing: no result deducts, under either
    // scoring system. Clamped rather than rejected because normalise() is also
    // the validation path and never throws — SettingsValidator says so properly.
    public function normalise(mixed $raw): array
    {
        $values = (is_array($raw) ? $raw : []) + $this->defaults;

        return array_map(
            static fn (mixed $v): mixed => is_numeric($v) ? max(0, $v + 0) : $v,
            $values
        );
    }
}
