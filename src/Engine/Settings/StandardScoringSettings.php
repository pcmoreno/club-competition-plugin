<?php

declare(strict_types=1);

namespace SCS\Engine\Settings;

use SCS\Engine\Settings\Setting\ByeTypes;
use SCS\Engine\Settings\Setting\GameOutcomes;
use SCS\Engine\Settings\Setting\TiebreakConfig;
use SCS\Engine\Settings\Setting\Tiebreakers;
use SCS\Entity\Enum\FieldType;
use SCS\Entity\Enum\ScoringOutcome;
use SCS\Entity\Enum\ScoringSettingsGroup;
use SCS\Entity\Enum\StandingsMetric;

final class StandardScoringSettings implements TournamentScoringSettings
{
    use SharedScoringSettings;

    // pairing_bye is reserved: the engine assigns it to the odd player, so it can't be deleted.
    // A full point here, where a ladder system prices it at two thirds.
    public const DEFAULT_BYE_TYPES = [
        ['key' => 'pairing_bye', 'label' => 'Pairing bye',       'points' => 1.0, 'reserved' => true],
        ['key' => 'club_duty',   'label' => 'Club duty',         'points' => 0.5],
        ['key' => 'personal',    'label' => 'Personal (absent)', 'points' => 0.0],
    ];

    public const DEFAULT_GAME_OUTCOMES = ['win' => 1.0, 'draw' => 0.5, 'loss' => 0.0];

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
        private readonly array $tiebreakers = Tiebreakers::DEFAULT,
        private readonly array $tiebreakConfig = TiebreakConfig::DEFAULT,
    ) {
    }

    public function rankBy(): StandingsMetric
    {
        return $this->rankByMetric;
    }

    /** @return array<string,mixed> */
    public function getSettings(): array
    {
        return [
            GameOutcomes::KEY => $this->gameOutcomes,
            ByeTypes::KEY     => $this->byeTypes,
            'rankBy'          => $this->rankByMetric->value,
            Tiebreakers::KEY    => array_map(static fn (StandingsMetric $m) => $m->value, $this->tiebreakers),
            TiebreakConfig::KEY => $this->tiebreakConfig,
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
            (new Tiebreakers())->field(),
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

        return new self(
            gameOutcomes:   (new GameOutcomes(self::DEFAULT_GAME_OUTCOMES))->normalise($values[GameOutcomes::KEY] ?? null),
            byeTypes:       (new ByeTypes(self::DEFAULT_BYE_TYPES))->normalise($values[ByeTypes::KEY] ?? null),
            rankByMetric:   $rankBy,
            tiebreakers:    (new Tiebreakers())->normalise($values[Tiebreakers::KEY] ?? null),
            tiebreakConfig: (new TiebreakConfig())->normalise($values[TiebreakConfig::KEY] ?? null),
        );
    }

    // rankBy excludes the tiebreak-only metrics (see StandingsMetric::canRankBy).
    /** @return list<array<string,string>> */
    private static function rankByOptions(): array
    {
        return array_values(array_filter(
            Tiebreakers::metricOptions(),
            static fn (array $option) => StandingsMetric::from($option['value'])->canRankBy()
        ));
    }

}
