<?php

declare(strict_types=1);

namespace SCS\Engine\Settings;

use SCS\Engine\Settings\Setting\Algorithm;
use SCS\Engine\Settings\Setting\BottomUpPairing;
use SCS\Engine\Settings\Setting\ByeChoice;
use SCS\Engine\Settings\Setting\Colors;
use SCS\Engine\Settings\Setting\ColorTiebreak;
use SCS\Engine\Settings\Setting\GameCorrection;
use SCS\Engine\Settings\Setting\NumberOfRounds;
use SCS\Engine\Settings\Setting\PairingVariant;
use SCS\Engine\Settings\Setting\ScoreCorrection;
use SCS\Engine\Settings\Setting\SkipLimit;
use SCS\Engine\Settings\Setting\StrictOrder;
use SCS\Entity\Enum\ColorPriority;
use SCS\Entity\Enum\ColorRule;
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
        private readonly ?int $numberOfRounds = null,
    ) {
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
            numberOfRounds:  (new NumberOfRounds())->normalise($values[NumberOfRounds::KEY] ?? null),
        );
    }
}
