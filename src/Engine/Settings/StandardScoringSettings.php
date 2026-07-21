<?php

declare(strict_types=1);

namespace SCS\Engine\Settings;

use SCS\Entity\Enum\BuchholzMethod;
use SCS\Entity\Enum\FieldType;
use SCS\Entity\Enum\ScoringOutcome;
use SCS\Entity\Enum\ScoringSettingsGroup;
use SCS\Entity\Enum\StandingsMetric;
use SCS\Entity\Enum\TprMethod;

final class StandardScoringSettings implements TournamentScoringSettings
{
    // pairing_bye is reserved: the engine assigns it to the odd player, so it can't be deleted.
    public const DEFAULT_BYE_TYPES = [
        ['key' => 'pairing_bye', 'label' => 'Pairing bye',       'points' => 1.0, 'reserved' => true],
        ['key' => 'club_duty',   'label' => 'Club duty',         'points' => 0.5],
        ['key' => 'personal',    'label' => 'Personal (absent)', 'points' => 0.0],
    ];

    private const DEFAULT_GAME_OUTCOMES = ['win' => 1.0, 'draw' => 0.5, 'loss' => 0.0];

    private const DEFAULT_TIEBREAK_CONFIG = [
        'direct_encounter'   => ['maxGroup' => 2],
        'buchholz'           => ['method' => 'baku_2023'],
        'performance_rating' => ['method' => 'fide_dp'],
    ];

    /**
     * @param array<string,float>              $gameOutcomes  ScoringOutcome value => points
     * @param list<array<string,mixed>>        $byeTypes      each {key,label,points,reserved?}
     * @param list<StandingsMetric>            $tiebreakers   ordered
     * @param array<string,array<string,mixed>> $tiebreakConfig per-metric params
     */
    public function __construct(
        public readonly array $gameOutcomes = self::DEFAULT_GAME_OUTCOMES,
        public readonly array $byeTypes = self::DEFAULT_BYE_TYPES,
        public readonly StandingsMetric $rankBy = StandingsMetric::Points,
        public readonly array $tiebreakers = [
            StandingsMetric::SonnebornBerger,
            StandingsMetric::Wins,
            StandingsMetric::DirectEncounter,
        ],
        public readonly array $tiebreakConfig = self::DEFAULT_TIEBREAK_CONFIG,
    ) {
    }

    public function pointsFor(ScoringOutcome $outcome): float
    {
        return (float)($this->gameOutcomes[$outcome->value] ?? 0.0);
    }

    public function byePoints(string $key): float
    {
        foreach ($this->byeTypes as $bye) {
            if (($bye['key'] ?? null) === $key) {
                return (float)($bye['points'] ?? 0.0);
            }
        }

        return 0.0;
    }

    public function directEncounterMaxGroup(): int
    {
        return (int)($this->tiebreakConfig['direct_encounter']['maxGroup'] ?? 2);
    }

    public function buchholzMethod(): BuchholzMethod
    {
        return BuchholzMethod::tryFrom((string)($this->tiebreakConfig['buchholz']['method'] ?? '')) ?? BuchholzMethod::Baku2023;
    }

    public function tprMethod(): TprMethod
    {
        return TprMethod::tryFrom((string)($this->tiebreakConfig['performance_rating']['method'] ?? '')) ?? TprMethod::FideDp;
    }

    /** @return array<string,mixed> */
    public function getSettings(): array
    {
        return [
            'gameOutcomes'   => $this->gameOutcomes,
            'byeTypes'       => $this->byeTypes,
            'rankBy'         => $this->rankBy->value,
            'tiebreakers'    => array_map(static fn (StandingsMetric $m) => $m->value, $this->tiebreakers),
            'tiebreakConfig' => $this->tiebreakConfig,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function getSettingsFields(): array
    {
        return [
            [
                'group'  => ScoringSettingsGroup::GameOutcomes->value,
                'type'   => FieldType::Number->value,
                'fields' => [
                    ['key' => ScoringOutcome::Win->value,  'label' => ScoringOutcome::Win->label(),  'default' => 1.0, 'step' => 0.5],
                    ['key' => ScoringOutcome::Draw->value, 'label' => ScoringOutcome::Draw->label(), 'default' => 0.5, 'step' => 0.5],
                    ['key' => ScoringOutcome::Loss->value, 'label' => ScoringOutcome::Loss->label(), 'default' => 0.0, 'step' => 0.5],
                ],
            ],
            [
                'group'        => ScoringSettingsGroup::ByeTypes->value,
                'type'         => FieldType::KeyedNumberList->value,
                'reservedKeys' => ['pairing_bye'],
                'default'      => self::DEFAULT_BYE_TYPES,
            ],
            [
                'group'   => ScoringSettingsGroup::RankBy->value,
                'type'    => FieldType::Select->value,
                'options' => self::metricOptions(),
                'default' => StandingsMetric::Points->value,
            ],
            [
                'group'   => ScoringSettingsGroup::Tiebreakers->value,
                'type'    => FieldType::OrderedMultiSelect->value,
                'options' => self::metricOptions(),
                'default' => ['sonneborn_berger', 'wins', 'direct_encounter'],
                'config'  => self::tiebreakConfigSchema(),
            ],
        ];
    }

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values): static
    {
        $defaults = new self();

        $rankBy = isset($values['rankBy'])
            ? (StandingsMetric::tryFrom((string)$values['rankBy']) ?? $defaults->rankBy)
            : $defaults->rankBy;

        $tiebreakers = isset($values['tiebreakers']) && is_array($values['tiebreakers'])
            ? array_values(array_filter(array_map(
                static fn ($v) => StandingsMetric::tryFrom((string)$v),
                $values['tiebreakers']
            )))
            : $defaults->tiebreakers;

        return new self(
            gameOutcomes:   ($values['gameOutcomes'] ?? []) + $defaults->gameOutcomes,
            byeTypes:       $values['byeTypes'] ?? $defaults->byeTypes,
            rankBy:         $rankBy,
            tiebreakers:    $tiebreakers ?: $defaults->tiebreakers,
            tiebreakConfig: array_replace_recursive($defaults->tiebreakConfig, $values['tiebreakConfig'] ?? []),
        );
    }

    /** @return list<array<string,string>> */
    private static function metricOptions(): array
    {
        return array_map(
            static fn (StandingsMetric $m) => ['value' => $m->value, 'label' => $m->label()],
            StandingsMetric::cases()
        );
    }

    // Parametric tiebreakers expose sub-fields, revealed only when the metric is selected.
    /** @return array<string,list<array<string,mixed>>> */
    private static function tiebreakConfigSchema(): array
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
                    'default' => BuchholzMethod::Baku2023->value,
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
