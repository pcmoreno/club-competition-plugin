<?php

declare(strict_types=1);

namespace SCS\Engine\Scoring\Metric;

use SCS\Engine\Scoring\ScoringContext;
use SCS\Engine\Settings\TournamentScoringSettings;
use SCS\Entity\Enum\ScoringOutcome;
use SCS\Entity\Enum\StandingsMetric;

final class WinsCalculator implements MetricCalculatorInterface
{
    public function metric(): StandingsMetric
    {
        return StandingsMetric::Wins;
    }

    public function isImplemented(TournamentScoringSettings $settings): bool
    {
        return true;
    }

    public function calculate(ScoringContext $context): array
    {
        $wins = [];

        foreach ($context->playerIds as $id) {
            $count = 0;
            foreach ($context->gamesByPlayer[$id] ?? [] as $game) {
                if ($game['outcome'] === ScoringOutcome::Win) {
                    $count++;
                }
            }
            $wins[$id] = (float)$count;
        }

        return $wins;
    }
}
