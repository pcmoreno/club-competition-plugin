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
use SCS\Engine\Settings\Setting\TiebreakConfig;
use SCS\Engine\Settings\Setting\Tiebreakers;
use SCS\Engine\Settings\Setting\TopValue;
use SCS\Engine\Settings\Setting\Valuation;
use SCS\Engine\Settings\Setting\ValueDecimals;
use SCS\Engine\Settings\Setting\ValueMultiplier;
use SCS\Engine\Settings\Setting\ValueStep;
use SCS\Engine\Settings\Setting\ValueStepEvery;
use SCS\Entity\Enum\AssignValuesOn;
use SCS\Entity\Enum\InitialValueOrder;
use SCS\Entity\Enum\RevaluationMode;
use SCS\Entity\Enum\ScoringSettingsGroup;
use SCS\Entity\Enum\StandingsMetric;
use SCS\Entity\Enum\ValuationMethod;

/**
 * Keizer scoring: a player's score is their own value, plus a share of each
 * opponent's value for every game, plus a share of their own for every absence.
 *
 *     score = OwnV + Σ games Par(result) × OppV + Σ absences Par(reason) × OwnV
 *
 * The Par values are the shared GameOutcomes and ByeTypes knobs — the same
 * numbers standard scoring adds to a total, here used as coefficients. Game
 * outcomes keep their defaults; all three bye types change, because a fraction
 * of own value is a different quantity from a share of a point: the pairing bye
 * 1.0 to 0.6667, club duty 0.5 to 0.6667, and personal absence 0.0 to 0.3333.
 * That last one is the one to know about — an absence a member reported
 * themselves scores nothing under standard scoring and a third of their value
 * here.
 */
final class KeizerScoringSettings implements TournamentScoringSettings
{
    use SharedScoringSettings;

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
        private readonly array $tiebreakers = Tiebreakers::DEFAULT,
        private readonly array $tiebreakConfig = TiebreakConfig::DEFAULT,
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

    // The Keizer score is the ranking metric by definition; it isn't chosen.
    public function rankBy(): StandingsMetric
    {
        return StandingsMetric::KeizerScore;
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
            Tiebreakers::KEY     => array_map(static fn (StandingsMetric $m) => $m->value, $this->tiebreakers),
            TiebreakConfig::KEY  => $this->tiebreakConfig,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function getSettingsFields(): array
    {
        return [
            (new GameOutcomes(self::DEFAULT_GAME_OUTCOMES))->field(),
            (new ByeTypes(self::DEFAULT_BYE_TYPES))->field(),
            self::group(ScoringSettingsGroup::Calculation, [
                (new AddInitialValue())->field(),
            ]),
            // Selects first, then the numbers they govern — which of the
            // numbers apply depends on the valuation method, and each says so
            // through enabledBy rather than by disappearing.
            self::group(ScoringSettingsGroup::PlayerValuation, [
                (new Valuation())->field(),
                (new AssignValues())->field(),
                (new InitialOrder())->field(),
                (new Revaluation())->field(),
                (new TopValue())->field(),
                (new BottomValue())->field(),
                (new ValueStep())->field(),
                (new ValueStepEvery())->field(),
                (new ValueMultiplier())->field(),
            ]),
            // ScoreDecimals is deliberately not offered: standings_snapshots
            // stores keizer_score as an integer, so the score is rounded whole
            // after this setting has been honoured and choosing decimals would
            // change nothing an organiser can see. Value decimals do apply —
            // ValueLadder rounds the ladder itself. Give the column a decimal
            // type and this belongs back in the group.
            self::group(ScoringSettingsGroup::Rounding, [
                (new ValueDecimals())->field(),
            ]),
            self::group(ScoringSettingsGroup::Aalsmeer, [
                (new AalsmeerRounds())->field(),
                (new AalsmeerOffset())->field(),
            ]),
            (new Tiebreakers())->field(),
        ];
    }

    /**
     * @param  list<array<string,mixed>> $fields
     * @return array<string,mixed>
     */
    private static function group(ScoringSettingsGroup $group, array $fields): array
    {
        return [
            'group'  => $group->value,
            'label'  => $group->label(),
            'fields' => $fields,
        ];
    }

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values): static
    {
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
            tiebreakers:     (new Tiebreakers())->normalise($values[Tiebreakers::KEY] ?? null),
            tiebreakConfig:  (new TiebreakConfig())->normalise($values[TiebreakConfig::KEY] ?? null),
        );
    }
}
