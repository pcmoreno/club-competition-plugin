<?php

declare(strict_types=1);

namespace SCS\Engine\Settings;

use SCS\Engine\Settings\Setting\AalsmeerOffset;
use SCS\Engine\Settings\Setting\AalsmeerRounds;
use SCS\Engine\Settings\Setting\AddInitialValue;
use SCS\Engine\Settings\Setting\AssignValues;
use SCS\Engine\Settings\Setting\BottomValue;
use SCS\Engine\Settings\Setting\ByeTypes;
use SCS\Engine\Settings\Setting\GameOutcomes;
use SCS\Engine\Settings\Setting\InitialOrder;
use SCS\Engine\Settings\Setting\Revaluation;
use SCS\Engine\Settings\Setting\ScoreDecimals;
use SCS\Engine\Settings\Setting\TopValue;
use SCS\Engine\Settings\Setting\Valuation;
use SCS\Engine\Settings\Setting\ValueDecimals;
use SCS\Engine\Settings\Setting\ValueMultiplier;
use SCS\Engine\Settings\Setting\ValueStep;
use SCS\Engine\Settings\Setting\ValueStepEvery;
use SCS\Entity\Enum\AssignValuesOn;
use SCS\Entity\Enum\BuchholzMethod;
use SCS\Entity\Enum\InitialValueOrder;
use SCS\Entity\Enum\RevaluationMode;
use SCS\Entity\Enum\ScoringOutcome;
use SCS\Entity\Enum\StandingsMetric;
use SCS\Entity\Enum\TprMethod;
use SCS\Entity\Enum\ValuationMethod;

/**
 * Keizer scoring: a player's score is their own value, plus a share of each
 * opponent's value for every game, plus a share of their own for every absence.
 *
 *     score = OwnV + Σ games Par(result) × OppV + Σ absences Par(reason) × OwnV
 *
 * The Par values are the shared GameOutcomes and ByeTypes knobs — the same
 * numbers standard scoring adds to a total, here used as coefficients. Only the
 * defaults differ, and only for the pairing bye: a full point in a Swiss event,
 * two thirds in a ladder one.
 */
final class KeizerScoringSettings implements TournamentScoringSettings
{
    /**
     * Two thirds for the pairing bye, which is Sevilla's documented default for
     * ladder systems and what the club's own history computes back to.
     */
    public const DEFAULT_BYE_TYPES = [
        ['key' => 'pairing_bye', 'label' => 'Pairing bye',       'points' => 0.6667, 'reserved' => true],
        ['key' => 'club_duty',   'label' => 'Club duty',         'points' => 0.6667],
        ['key' => 'personal',    'label' => 'Personal (absent)', 'points' => 0.3333],
    ];

    public const DEFAULT_GAME_OUTCOMES = ['win' => 1.0, 'draw' => 0.5, 'loss' => 0.0];

    private const DEFAULT_TIEBREAK_CONFIG = [
        'direct_encounter'   => ['maxGroup' => 2],
        'buchholz'           => ['method' => 'classic'],
        'performance_rating' => ['method' => 'fide_dp'],
    ];

    /**
     * @param array<string,mixed>               $gameOutcomes
     * @param list<array<string,mixed>>         $byeTypes
     * @param list<StandingsMetric>             $tiebreakers    behind the Keizer score
     * @param array<string,array<string,mixed>> $tiebreakConfig
     */
    public function __construct(
        private readonly array $gameOutcomes = self::DEFAULT_GAME_OUTCOMES,
        private readonly array $byeTypes = self::DEFAULT_BYE_TYPES,
        private readonly ValuationMethod $valuation = ValuationMethod::PositionRange,
        private readonly int $topValue = TopValue::DEFAULT,
        private readonly int $bottomValue = BottomValue::DEFAULT,
        private readonly InitialValueOrder $initialOrder = InitialValueOrder::Rating,
        private readonly bool $addInitialValue = AddInitialValue::DEFAULT,
        private readonly ?int $valueDecimals = ValueDecimals::DEFAULT,
        private readonly ?int $scoreDecimals = ScoreDecimals::DEFAULT,
        private readonly int $valueStep = ValueStep::DEFAULT,
        private readonly int $valueStepEvery = ValueStepEvery::DEFAULT,
        private readonly int $valueMultiplier = ValueMultiplier::DEFAULT,
        private readonly AssignValuesOn $assignValuesOn = AssignValuesOn::Score,
        private readonly RevaluationMode $revaluation = RevaluationMode::Classic,
        private readonly int $aalsmeerRounds = 0,
        private readonly int $aalsmeerOffset = 0,
        private readonly array $tiebreakers = [
            StandingsMetric::Wins,
            StandingsMetric::PerformanceRating,
        ],
        private readonly array $tiebreakConfig = self::DEFAULT_TIEBREAK_CONFIG,
    ) {
    }

    public function valuation(): ValuationMethod
    {
        return $this->valuation;
    }

    public function topValue(): int
    {
        return $this->topValue;
    }

    public function bottomValue(): int
    {
        return $this->bottomValue;
    }

    public function initialOrder(): InitialValueOrder
    {
        return $this->initialOrder;
    }

    public function valueStep(): int
    {
        return $this->valueStep;
    }

    public function valueStepEvery(): int
    {
        return $this->valueStepEvery;
    }

    public function valueMultiplier(): int
    {
        return $this->valueMultiplier;
    }

    public function assignValuesOn(): AssignValuesOn
    {
        return $this->assignValuesOn;
    }

    public function revaluation(): RevaluationMode
    {
        return $this->revaluation;
    }

    public function addsInitialValue(): bool
    {
        return $this->addInitialValue;
    }

    public function valueDecimals(): ?int
    {
        return $this->valueDecimals;
    }

    public function scoreDecimals(): ?int
    {
        return $this->scoreDecimals;
    }

    /**
     * How many extra helpings of own value this round carries.
     *
     * Full strength for the first `offset` rounds, then one less each round
     * until it runs out. Zero rounds disables it, which is the default.
     */
    public function aalsmeerBonus(int $roundNumber): int
    {
        if ($this->aalsmeerRounds < 1) {
            return 0;
        }
        if ($roundNumber <= $this->aalsmeerOffset) {
            return $this->aalsmeerRounds;
        }

        return max(0, $this->aalsmeerRounds - ($roundNumber - $this->aalsmeerOffset));
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

    /** @return list<string> */
    public function reservedByeKeys(): array
    {
        return (new ByeTypes(self::DEFAULT_BYE_TYPES))->reservedKeys();
    }

    // The Keizer score is the ranking metric by definition; it isn't chosen.
    public function rankBy(): StandingsMetric
    {
        return StandingsMetric::KeizerScore;
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
            GameOutcomes::KEY    => $this->gameOutcomes,
            ByeTypes::KEY        => $this->byeTypes,
            Valuation::KEY       => $this->valuation->value,
            TopValue::KEY        => $this->topValue,
            BottomValue::KEY     => $this->bottomValue,
            InitialOrder::KEY    => $this->initialOrder->value,
            ValueStep::KEY       => $this->valueStep,
            ValueStepEvery::KEY  => $this->valueStepEvery,
            ValueMultiplier::KEY => $this->valueMultiplier,
            AssignValues::KEY    => $this->assignValuesOn->value,
            Revaluation::KEY     => $this->revaluation->value,
            AddInitialValue::KEY => $this->addInitialValue,
            ValueDecimals::KEY   => $this->valueDecimals,
            ScoreDecimals::KEY   => $this->scoreDecimals,
            AalsmeerRounds::KEY  => $this->aalsmeerRounds,
            AalsmeerOffset::KEY  => $this->aalsmeerOffset,
            'tiebreakers'        => array_map(static fn (StandingsMetric $m) => $m->value, $this->tiebreakers),
            'tiebreakConfig'     => $this->tiebreakConfig,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function getSettingsFields(): array
    {
        return [
            (new GameOutcomes(self::DEFAULT_GAME_OUTCOMES))->field(),
            (new ByeTypes(self::DEFAULT_BYE_TYPES))->field(),
            (new Valuation())->field(),
            (new TopValue())->field(),
            (new BottomValue())->field(),
            (new InitialOrder())->field(),
            (new ValueStep())->field(),
            (new ValueStepEvery())->field(),
            (new ValueMultiplier())->field(),
            (new AssignValues())->field(),
            (new Revaluation())->field(),
            (new AddInitialValue())->field(),
            (new ValueDecimals())->field(),
            (new ScoreDecimals())->field(),
            (new AalsmeerRounds())->field(),
            (new AalsmeerOffset())->field(),
        ];
    }

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values): static
    {
        $defaults = new self();

        $tiebreakers = isset($values['tiebreakers']) && is_array($values['tiebreakers'])
            ? array_values(array_filter(array_map(
                static fn ($v) => StandingsMetric::tryFrom((string)$v),
                $values['tiebreakers']
            )))
            : $defaults->tiebreakers();

        $tiebreakConfig = array_replace_recursive($defaults->tiebreakConfig, $values['tiebreakConfig'] ?? []);

        $buchholz = BuchholzMethod::tryFrom((string)($tiebreakConfig['buchholz']['method'] ?? ''));
        if ($buchholz === null || !$buchholz->isImplemented()) {
            $tiebreakConfig['buchholz']['method'] = $defaults->buchholzMethod()->value;
        }

        $tpr = TprMethod::tryFrom((string)($tiebreakConfig['performance_rating']['method'] ?? ''));
        if ($tpr === null || !$tpr->isImplemented()) {
            $tiebreakConfig['performance_rating']['method'] = $defaults->tprMethod()->value;
        }

        return new self(
            gameOutcomes:    (new GameOutcomes(self::DEFAULT_GAME_OUTCOMES))->normalise($values[GameOutcomes::KEY] ?? null),
            byeTypes:        (new ByeTypes(self::DEFAULT_BYE_TYPES))->normalise($values[ByeTypes::KEY] ?? null),
            valuation:       (new Valuation())->normalise($values[Valuation::KEY] ?? null),
            topValue:        (new TopValue())->normalise($values[TopValue::KEY] ?? null),
            bottomValue:     (new BottomValue())->normalise($values[BottomValue::KEY] ?? null),
            initialOrder:    (new InitialOrder())->normalise($values[InitialOrder::KEY] ?? null),
            addInitialValue: (new AddInitialValue())->normalise($values[AddInitialValue::KEY] ?? null),
            // array_key_exists, not ??: null is a real value here ("don't round"),
            // so an absent key has to fall back to the default rather than read
            // as a deliberate choice not to round.
            valueDecimals:   array_key_exists(ValueDecimals::KEY, $values)
                ? (new ValueDecimals())->normalise($values[ValueDecimals::KEY])
                : ValueDecimals::DEFAULT,
            scoreDecimals:   array_key_exists(ScoreDecimals::KEY, $values)
                ? (new ScoreDecimals())->normalise($values[ScoreDecimals::KEY])
                : ScoreDecimals::DEFAULT,
            valueStep:       (new ValueStep())->normalise($values[ValueStep::KEY] ?? null),
            valueStepEvery:  (new ValueStepEvery())->normalise($values[ValueStepEvery::KEY] ?? null),
            valueMultiplier: (new ValueMultiplier())->normalise($values[ValueMultiplier::KEY] ?? null),
            assignValuesOn:  (new AssignValues())->normalise($values[AssignValues::KEY] ?? null),
            revaluation:     (new Revaluation())->normalise($values[Revaluation::KEY] ?? null),
            aalsmeerRounds:  (new AalsmeerRounds())->normalise($values[AalsmeerRounds::KEY] ?? null),
            aalsmeerOffset:  (new AalsmeerOffset())->normalise($values[AalsmeerOffset::KEY] ?? null),
            tiebreakers:     $tiebreakers ?: $defaults->tiebreakers(),
            tiebreakConfig:  $tiebreakConfig,
        );
    }
}
