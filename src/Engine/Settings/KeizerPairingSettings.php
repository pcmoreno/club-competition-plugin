<?php

declare(strict_types=1);

namespace SCS\Engine\Settings;

use SCS\Engine\Settings\Setting\Algorithm;
use SCS\Engine\Settings\Setting\BottomUpPairing;
use SCS\Engine\Settings\Setting\ByeChoice;
use SCS\Engine\Settings\Setting\Colors;
use SCS\Engine\Settings\Setting\ColorTie;
use SCS\Engine\Settings\Setting\ColorTiebreak;
use SCS\Engine\Settings\Setting\GameCorrection;
use SCS\Engine\Settings\Setting\IgnoreMildColourPrefs;
use SCS\Engine\Settings\Setting\MaxColourDifference;
use SCS\Engine\Settings\Setting\MaxRematches;
use SCS\Engine\Settings\Setting\MaxSameColourRun;
use SCS\Engine\Settings\Setting\NumberOfRounds;
use SCS\Engine\Settings\Setting\PairingVariant;
use SCS\Engine\Settings\Setting\RematchWindow;
use SCS\Engine\Settings\Setting\ScoreCorrection;
use SCS\Engine\Settings\Setting\SkipLimit;
use SCS\Engine\Settings\Setting\StrictOrder;
use SCS\Engine\Settings\Setting\StrongerPreferenceWins;
use SCS\Entity\Enum\ColorPriority;
use SCS\Entity\Enum\ColorRule;
use SCS\Entity\Enum\ColorTieAward;
use SCS\Entity\Enum\ColorTieCriterion;
use SCS\Entity\Enum\FirstBoardColour;
use SCS\Entity\Enum\KeizerPairingVariant;
use SCS\Entity\Enum\PairingAlgorithm;
use SCS\Entity\Enum\PairingByeChoice;

/**
 * What a Keizer round's boards are built from.
 *
 * `NumberOfRounds` is composed because a Keizer season genuinely is open-ended —
 * the club adds a round every Tuesday and stops when the season does — unlike a
 * round-robin, whose count follows from the roster.
 */
final class KeizerPairingSettings implements TournamentPairingSettings
{
    public function __construct(
        private readonly KeizerPairingVariant $variant = KeizerPairingVariant::Score,
        private readonly int $scoreCorrection = ScoreCorrection::DEFAULT,
        private readonly int $gameCorrection = GameCorrection::DEFAULT,
        private readonly PairingAlgorithm $algorithm = PairingAlgorithm::Standard,
        private readonly int $limit = SkipLimit::DEFAULT,
        private readonly bool $strictOrder = StrictOrder::DEFAULT,
        private readonly bool $bottomUp = BottomUpPairing::DEFAULT,
        private readonly PairingByeChoice $byeChoice = PairingByeChoice::Random,
        private readonly ColorRule $colorRule = ColorRule::Alternating,
        private readonly ColorPriority $colorPriority = ColorPriority::HigherRanked,
        private readonly ColorTieCriterion $colorTie = ColorTieCriterion::LowerPairingNumber,
        private readonly ColorTieAward $colorTieAward = ColorTieAward::Alternate,
        private readonly FirstBoardColour $firstBoardColour = FirstBoardColour::Automatic,
        private readonly bool $ignoreMildColourPrefs = IgnoreMildColourPrefs::DEFAULT,
        private readonly bool $strongerPreferenceWins = StrongerPreferenceWins::DEFAULT,
        private readonly int $rematchWindow = RematchWindow::DEFAULT,
        private readonly int $maxRematches = MaxRematches::DEFAULT,
        private readonly int $maxColourDifference = MaxColourDifference::DEFAULT,
        private readonly int $maxSameColourRun = MaxSameColourRun::DEFAULT,
        private readonly ?int $numberOfRounds = null,
    ) {
    }

    public function colorTie(): ColorTieCriterion
    {
        return $this->colorTie;
    }

    public function colorTieAward(): ColorTieAward
    {
        return $this->colorTieAward;
    }

    public function firstBoardColour(): FirstBoardColour
    {
        return $this->firstBoardColour;
    }

    public function ignoresMildColourPrefs(): bool
    {
        return $this->ignoreMildColourPrefs;
    }

    public function strongerPreferenceWins(): bool
    {
        return $this->strongerPreferenceWins;
    }

    public function rematchWindow(): int
    {
        return $this->rematchWindow;
    }

    public function maxRematches(): int
    {
        return $this->maxRematches;
    }

    public function maxColourDifference(): int
    {
        return $this->maxColourDifference;
    }

    public function maxSameColourRun(): int
    {
        return $this->maxSameColourRun;
    }

    public function variant(): KeizerPairingVariant
    {
        return $this->variant;
    }

    public function scoreCorrection(): int
    {
        return $this->scoreCorrection;
    }

    public function gameCorrection(): int
    {
        return $this->gameCorrection;
    }

    public function algorithm(): PairingAlgorithm
    {
        return $this->algorithm;
    }

    // Zero for the standard algorithm, which never reaches past a neighbour.
    public function limit(): int
    {
        return $this->algorithm->usesLimit() ? $this->limit : 0;
    }

    public function strictOrder(): bool
    {
        return $this->strictOrder;
    }

    public function pairsFromBothEnds(): bool
    {
        return $this->bottomUp;
    }

    public function byeChoice(): PairingByeChoice
    {
        return $this->byeChoice;
    }

    public function colorRule(): ColorRule
    {
        return $this->colorRule;
    }

    public function colorPriority(): ColorPriority
    {
        return $this->colorPriority;
    }

    /** @return array<string,mixed> */
    public function getSettings(): array
    {
        return [
            PairingVariant::KEY  => $this->variant->value,
            ScoreCorrection::KEY => $this->scoreCorrection,
            GameCorrection::KEY  => $this->gameCorrection,
            Algorithm::KEY       => $this->algorithm->value,
            SkipLimit::KEY       => $this->limit,
            StrictOrder::KEY     => $this->strictOrder,
            BottomUpPairing::KEY => $this->bottomUp,
            ByeChoice::KEY       => $this->byeChoice->value,
            Colors::KEY          => $this->colorRule->value,
            ColorTiebreak::KEY   => $this->colorPriority->value,
            ColorTie::KEY               => $this->colorTie->value,
            ColorTie::AWARD_KEY         => $this->colorTieAward->value,
            ColorTie::FIRST_BOARD_KEY   => $this->firstBoardColour->value,
            IgnoreMildColourPrefs::KEY  => $this->ignoreMildColourPrefs,
            StrongerPreferenceWins::KEY => $this->strongerPreferenceWins,
            RematchWindow::KEY   => $this->rematchWindow,
            MaxRematches::KEY    => $this->maxRematches,
            MaxColourDifference::KEY => $this->maxColourDifference,
            MaxSameColourRun::KEY    => $this->maxSameColourRun,
            NumberOfRounds::KEY  => $this->numberOfRounds,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function getSettingsFields(): array
    {
        return [
            (new PairingVariant())->field(),
            (new ScoreCorrection())->field(),
            (new GameCorrection())->field(),
            (new Algorithm())->field(),
            (new SkipLimit())->field(),
            (new StrictOrder())->field(),
            (new BottomUpPairing())->field(),
            (new ByeChoice())->field(),
            (new Colors())->field(),
            (new ColorTiebreak())->field(),
            (new ColorTie())->field(),
            (new ColorTie())->awardField(),
            (new ColorTie())->firstBoardField(),
            (new IgnoreMildColourPrefs())->field(),
            (new StrongerPreferenceWins())->field(),
            (new RematchWindow())->field(),
            (new MaxRematches())->field(),
            (new MaxColourDifference())->field(),
            (new MaxSameColourRun())->field(),
            (new NumberOfRounds())->field(),
        ];
    }

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values): static
    {
        $gameCorrection = (new GameCorrection())->normalise($values[GameCorrection::KEY] ?? null);

        return new self(
            variant:         (new PairingVariant())->normalise($values[PairingVariant::KEY] ?? null),
            // The damping only makes sense while SC stays within GC — above it a
            // player with no games reads as better than one who has won
            // everything. Clamped here rather than in the Setting, which can't
            // see its sibling.
            scoreCorrection: min((new ScoreCorrection())->normalise($values[ScoreCorrection::KEY] ?? null), $gameCorrection),
            gameCorrection:  $gameCorrection,
            algorithm:       (new Algorithm())->normalise($values[Algorithm::KEY] ?? null),
            limit:           (new SkipLimit())->normalise($values[SkipLimit::KEY] ?? null),
            strictOrder:     (new StrictOrder())->normalise($values[StrictOrder::KEY] ?? null),
            bottomUp:        (new BottomUpPairing())->normalise($values[BottomUpPairing::KEY] ?? null),
            byeChoice:       (new ByeChoice())->normalise($values[ByeChoice::KEY] ?? null),
            colorRule:       (new Colors())->normalise($values[Colors::KEY] ?? null),
            colorPriority:   (new ColorTiebreak())->normalise($values[ColorTiebreak::KEY] ?? null),
            colorTie:         (new ColorTie())->normalise($values[ColorTie::KEY] ?? null),
            colorTieAward:    (new ColorTie())->normaliseAward($values[ColorTie::AWARD_KEY] ?? null),
            firstBoardColour: (new ColorTie())->normaliseFirstBoard($values[ColorTie::FIRST_BOARD_KEY] ?? null),
            ignoreMildColourPrefs:  (new IgnoreMildColourPrefs())->normalise($values[IgnoreMildColourPrefs::KEY] ?? null),
            strongerPreferenceWins: (new StrongerPreferenceWins())->normalise($values[StrongerPreferenceWins::KEY] ?? null),
            rematchWindow:   (new RematchWindow())->normalise($values[RematchWindow::KEY] ?? null),
            maxRematches:    (new MaxRematches())->normalise($values[MaxRematches::KEY] ?? null),
            maxColourDifference: (new MaxColourDifference())->normalise($values[MaxColourDifference::KEY] ?? null),
            maxSameColourRun:    (new MaxSameColourRun())->normalise($values[MaxSameColourRun::KEY] ?? null),
            numberOfRounds:  (new NumberOfRounds())->normalise($values[NumberOfRounds::KEY] ?? null),
        );
    }
}
