<?php

declare(strict_types=1);

namespace SCS\Engine\Scoring\Metric;

use SCS\Engine\Scoring\ScoringContext;
use SCS\Engine\Settings\StandardScoringSettings;
use SCS\Entity\Enum\ScoringOutcome;
use SCS\Entity\Enum\StandingsMetric;

// Sum of defeated opponents' points plus half of drawn opponents' points. Needs Pass-1 points.
final class SonnebornBergerCalculator implements MetricCalculatorInterface
{
    public function metric(): StandingsMetric
    {
        return StandingsMetric::SonnebornBerger;
    }

    public function isImplemented(StandardScoringSettings $settings): bool
    {
        return true;
    }

    public function calculate(ScoringContext $context): array
    {
        $sb = [];

        foreach ($context->playerIds as $id) {
            $total = 0.0;
            foreach ($context->gamesByPlayer[$id] ?? [] as $game) {
                $opponentPoints = $context->points[$game['opponent']] ?? 0.0;
                if ($game['outcome'] === ScoringOutcome::Win) {
                    $total += $opponentPoints;
                } elseif ($game['outcome'] === ScoringOutcome::Draw) {
                    $total += $opponentPoints / 2;
                }
            }
            $sb[$id] = $total;
        }

        return $sb;
    }
}
