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
use SCS\Exception\ConflictException;

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
            // ConflictException, not a bare RuntimeException: this is reachable
            // from a season stored before the system was constrained, and the
            // admin needs a 409 they can read rather than a generic 500.
            ScoringSystem::Keizer => throw new ConflictException('Keizer scoring is not implemented yet, so this round cannot be completed.'),
        };
    }
}
