<?php

declare(strict_types=1);

namespace SCS\Engine;

use SCS\Engine\Scoring\PlayerScoreCalculator;
use SCS\Engine\Scoring\ScoringStrategyInterface;
use SCS\Engine\Scoring\StandardScoring;
use SCS\Engine\Scoring\StandingsCalculator;
use SCS\Engine\Settings\StandardScoringSettings;
use SCS\Entity\Enum\ScoringSystem;
use SCS\Entity\Season;

// Builds the scoring strategy for a season, configured with that season's stored settings.
final class ScoringStrategyResolver
{
    public function __construct(
        private readonly PlayerScoreCalculator $playerScores,
        private readonly StandingsCalculator $standings,
    ) {
    }

    public function resolve(Season $season): ScoringStrategyInterface
    {
        return match ($season->pairing_system->scoringSystem()) {
            ScoringSystem::Standard => new StandardScoring(
                StandardScoringSettings::fromArray($season->scoring_settings ?? []),
                $this->playerScores,
                $this->standings,
            ),
            ScoringSystem::Keizer => throw new \RuntimeException('Keizer scoring is not implemented yet.'),
        };
    }
}
