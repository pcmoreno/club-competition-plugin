<?php

declare(strict_types=1);

namespace SCS\Engine\Settings\Setting;

use SCS\Entity\Enum\BuchholzMethod;
use SCS\Entity\Enum\FieldType;
use SCS\Entity\Enum\ScoringSettingsGroup;
use SCS\Entity\Enum\StandingsMetric;
use SCS\Entity\Enum\TprMethod;

/**
 * The ordered criteria that separate players on equal footing.
 *
 * Shared across scoring systems: what a season ranks *by* differs — classical
 * points here, a Keizer value there — but how it breaks a tie doesn't, and an
 * organiser shouldn't meet a different set of options depending on the system.
 */
final class Tiebreakers implements SettingInterface
{
    public const KEY = 'tiebreakers';

    public const DEFAULT = [
        StandingsMetric::SonnebornBerger,
        StandingsMetric::Wins,
        StandingsMetric::DirectEncounter,
    ];

    public function key(): string
    {
        return self::KEY;
    }

    /** @return array<string,mixed> */
    public function field(): array
    {
        return [
            'group'   => ScoringSettingsGroup::Tiebreakers->value,
            'type'    => FieldType::OrderedMultiSelect->value,
            'options' => self::metricOptions(),
            'default' => array_map(static fn (StandingsMetric $m) => $m->value, self::DEFAULT),
            'config'  => self::configSchema(),
        ];
    }

    /** @return list<StandingsMetric> */
    public function normalise(mixed $raw): array
    {
        if (!is_array($raw)) {
            return self::DEFAULT;
        }

        $metrics = array_values(array_filter(array_map(
            static fn ($value) => StandingsMetric::tryFrom((string)$value),
            $raw
        )));

        return $metrics === [] ? self::DEFAULT : $metrics;
    }

    /**
     * The metrics an organiser may pick from — everything except the ones a
     * system ranks by unconditionally, which would read zero anywhere else.
     *
     * @return list<array<string,string>>
     */
    public static function metricOptions(): array
    {
        return array_values(array_map(
            static fn (StandingsMetric $m) => ['value' => $m->value, 'label' => $m->label()],
            array_filter(StandingsMetric::cases(), static fn (StandingsMetric $m) => $m->isSelectable())
        ));
    }

    // Parametric tiebreakers expose sub-fields, revealed only when selected.
    /** @return array<string,list<array<string,mixed>>> */
    public static function configSchema(): array
    {
        return [
            StandingsMetric::DirectEncounter->value => [
                ['key' => 'maxGroup', 'label' => 'Apply only when at most N players are tied', 'type' => FieldType::Number->value, 'default' => 2, 'step' => 1],
            ],
            StandingsMetric::Buchholz->value => [
                [
                    'key'     => 'method',
                    'label'   => 'Method',
                    'type'    => FieldType::Select->value,
                    'options' => array_map(
                        static fn (BuchholzMethod $m) => ['value' => $m->value, 'label' => $m->label(), 'implemented' => $m->isImplemented()],
                        BuchholzMethod::cases()
                    ),
                    'default' => BuchholzMethod::Classic->value,
                ],
            ],
            StandingsMetric::PerformanceRating->value => [
                [
                    'key'     => 'method',
                    'label'   => 'Method',
                    'type'    => FieldType::Select->value,
                    'options' => array_map(
                        static fn (TprMethod $m) => ['value' => $m->value, 'label' => $m->label(), 'implemented' => $m->isImplemented()],
                        TprMethod::cases()
                    ),
                    'default' => TprMethod::FideDp->value,
                ],
            ],
        ];
    }
}
