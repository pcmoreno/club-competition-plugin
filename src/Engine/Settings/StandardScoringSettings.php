<?php

declare(strict_types=1);

namespace SCS\Engine\Settings;

use SCS\Engine\Settings\Setting\ByeTypes;
use SCS\Engine\Settings\Setting\GameOutcomes;
use SCS\Entity\Enum\BuchholzMethod;
use SCS\Entity\Enum\FieldType;
use SCS\Entity\Enum\ScoringOutcome;
use SCS\Entity\Enum\ScoringSettingsGroup;
use SCS\Entity\Enum\StandingsMetric;
use SCS\Entity\Enum\TprMethod;

final class StandardScoringSettings implements TournamentScoringSettings
{
    // pairing_bye is reserved: the engine assigns it to the odd player, so it can't be deleted.
    // A full point here, where a ladder system prices it at two thirds.
    public const DEFAULT_BYE_TYPES = [
        ['key' => 'pairing_bye', 'label' => 'Pairing bye',       'points' => 1.0, 'reserved' => true],
        ['key' => 'club_duty',   'label' => 'Club duty',         'points' => 0.5],
        ['key' => 'personal',    'label' => 'Personal (absent)', 'points' => 0.0],
    ];

    public const DEFAULT_GAME_OUTCOMES = ['win' => 1.0, 'draw' => 0.5, 'loss' => 0.0];

    // Buchholz defaults to Classic because it is the only implemented variant;
    // an unimplemented method makes the calculator no-op and the column read 0.
    private const DEFAULT_TIEBREAK_CONFIG = [
        'direct_encounter'   => ['maxGroup' => 2],
        'buchholz'           => ['method' => 'classic'],
        'performance_rating' => ['method' => 'fide_dp'],
    ];

    /**
     * @param array<string,mixed>               $gameOutcomes   ScoringOutcome value => points
     * @param list<array<string,mixed>>         $byeTypes       each {key,label,points,reserved?}
     * @param list<StandingsMetric>             $tiebreakers    ordered
     * @param array<string,array<string,mixed>> $tiebreakConfig per-metric params
     */
    public function __construct(
        private readonly array $gameOutcomes = self::DEFAULT_GAME_OUTCOMES,
        private readonly array $byeTypes = self::DEFAULT_BYE_TYPES,
        private readonly StandingsMetric $rankByMetric = StandingsMetric::Points,
        private readonly array $tiebreakers = [
            StandingsMetric::SonnebornBerger,
            StandingsMetric::Wins,
            StandingsMetric::DirectEncounter,
        ],
        private readonly array $tiebreakConfig = self::DEFAULT_TIEBREAK_CONFIG,
    ) {
    }

    /** @return list<string> */
    public function reservedByeKeys(): array
    {
        return (new ByeTypes(self::DEFAULT_BYE_TYPES))->reservedKeys();
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

    public function rankBy(): StandingsMetric
    {
        return $this->rankByMetric;
    }

    /** @return list<StandingsMetric> */
    public function tiebreakers(): array
    {
        return $this->tiebreakers;
    }

    public function directEncounterMaxGroup(): int
    {
        return (int)($this->tiebreakConfig['direct_encounter']['maxGroup'] ?? 2);
    }

    public function buchholzMethod(): BuchholzMethod
    {
        // Classic, not Baku2023: the fallback must name an implemented variant,
        // or an unparseable stored value silently disables the metric.
        return BuchholzMethod::tryFrom((string)($this->tiebreakConfig['buchholz']['method'] ?? '')) ?? BuchholzMethod::Classic;
    }

    public function tprMethod(): TprMethod
    {
        return TprMethod::tryFrom((string)($this->tiebreakConfig['performance_rating']['method'] ?? '')) ?? TprMethod::FideDp;
    }

    /** @return array<string,mixed> */
    public function getSettings(): array
    {
        return [
            GameOutcomes::KEY => $this->gameOutcomes,
            ByeTypes::KEY     => $this->byeTypes,
            'rankBy'          => $this->rankByMetric->value,
            'tiebreakers'     => array_map(static fn (StandingsMetric $m) => $m->value, $this->tiebreakers),
            'tiebreakConfig'  => $this->tiebreakConfig,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function getSettingsFields(): array
    {
        return [
            (new GameOutcomes(self::DEFAULT_GAME_OUTCOMES))->field(),
            (new ByeTypes(self::DEFAULT_BYE_TYPES))->field(),
            [
                'group'   => ScoringSettingsGroup::RankBy->value,
                'type'    => FieldType::Select->value,
                'options' => self::rankByOptions(),
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

        // Fall back to the default for an unknown metric, and for a tiebreak-only
        // one: settings stored before rankBy was constrained may still hold it,
        // and honouring it would collapse the whole field onto rank 1.
        $rankBy = isset($values['rankBy'])
            ? (StandingsMetric::tryFrom((string)$values['rankBy']) ?? $defaults->rankBy())
            : $defaults->rankBy();
        if (!$rankBy->canRankBy()) {
            $rankBy = $defaults->rankBy();
        }

        $tiebreakers = isset($values['tiebreakers']) && is_array($values['tiebreakers'])
            ? array_values(array_filter(array_map(
                static fn ($v) => StandingsMetric::tryFrom((string)$v),
                $values['tiebreakers']
            )))
            : $defaults->tiebreakers();

        $tiebreakConfig = array_replace_recursive($defaults->tiebreakConfig, $values['tiebreakConfig'] ?? []);

        // Same defence as rankBy above, for the parametric tiebreakers. Settings
        // saved before SettingsValidator started rejecting unimplemented methods
        // still name one, and honouring it makes the calculator skip itself so
        // the column reads 0 for every player while the UI shows it configured.
        //
        // Coercing here rather than migrating repairs the behaviour on read, and
        // because getSettings() emits this array the settings screen reports the
        // corrected value too — so the next save persists the repair.
        $buchholz = BuchholzMethod::tryFrom((string)($tiebreakConfig['buchholz']['method'] ?? ''));
        if ($buchholz === null || !$buchholz->isImplemented()) {
            $tiebreakConfig['buchholz']['method'] = $defaults->buchholzMethod()->value;
        }

        $tpr = TprMethod::tryFrom((string)($tiebreakConfig['performance_rating']['method'] ?? ''));
        if ($tpr === null || !$tpr->isImplemented()) {
            $tiebreakConfig['performance_rating']['method'] = $defaults->tprMethod()->value;
        }

        return new self(
            gameOutcomes:   (new GameOutcomes(self::DEFAULT_GAME_OUTCOMES))->normalise($values[GameOutcomes::KEY] ?? null),
            byeTypes:       (new ByeTypes(self::DEFAULT_BYE_TYPES))->normalise($values[ByeTypes::KEY] ?? null),
            rankByMetric:   $rankBy,
            tiebreakers:    $tiebreakers ?: $defaults->tiebreakers(),
            tiebreakConfig: $tiebreakConfig,
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

    // rankBy excludes the tiebreak-only metrics (see StandingsMetric::canRankBy).
    /** @return list<array<string,string>> */
    private static function rankByOptions(): array
    {
        return array_values(array_filter(
            self::metricOptions(),
            static fn (array $option) => StandingsMetric::from($option['value'])->canRankBy()
        ));
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
