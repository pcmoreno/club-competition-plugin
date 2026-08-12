<?php

declare(strict_types=1);

namespace SCS\Engine\Scoring\Metric;

use SCS\Engine\Scoring\ScoringContext;
use SCS\Engine\Settings\TournamentScoringSettings;
use SCS\Entity\Enum\StandingsMetric;

// Sum of game-outcome points (win/draw/loss) plus bye points, per the settings.
final class PointsCalculator implements MetricCalculatorInterface
{
    public function metric(): StandingsMetric
    {
        return StandingsMetric::Points;
    }

    public function isImplemented(TournamentScoringSettings $settings): bool
    {
        return true;
    }

    /** @return array<int,float> every player has points, so this one never returns null */
    public function calculate(ScoringContext $context): array
    {
        $settings = $context->settings;
        $points   = [];

        // scoredPlayerIds, not playerIds: opponents who have left the roster still
        // need a points total, because the tiebreak metrics read it.
        foreach ($context->scoredPlayerIds() as $id) {
            $total = 0.0;

            foreach ($context->gamesByPlayer[$id] ?? [] as $game) {
                $total += $settings->pointsFor($game['outcome']);
            }

            foreach ($context->byesByPlayer[$id] ?? [] as $byeKey) {
                $total += $settings->byePoints($byeKey);
            }

            $points[$id] = $total;
        }

        return $points;
    }
}
