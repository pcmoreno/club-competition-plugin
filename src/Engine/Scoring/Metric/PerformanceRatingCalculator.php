<?php

declare(strict_types=1);

namespace SCS\Engine\Scoring\Metric;

use SCS\Engine\Scoring\ScoringContext;
use SCS\Engine\Settings\StandardScoringSettings;
use SCS\Entity\Enum\ScoringOutcome;
use SCS\Entity\Enum\StandingsMetric;

// FIDE performance rating: average opponent rating + dp(p), p = game score / games played.
final class PerformanceRatingCalculator implements MetricCalculatorInterface
{
    // dp for p in 0.50..1.00 (percent index 50..100); mirrored for p < 0.50. FIDE rating table.
    private const DP = [
        50 => 0, 51 => 7, 52 => 14, 53 => 21, 54 => 29, 55 => 36, 56 => 43, 57 => 50, 58 => 57, 59 => 65,
        60 => 72, 61 => 80, 62 => 87, 63 => 95, 64 => 102, 65 => 110, 66 => 117, 67 => 125, 68 => 133, 69 => 141,
        70 => 149, 71 => 158, 72 => 166, 73 => 175, 74 => 184, 75 => 193, 76 => 202, 77 => 211, 78 => 220, 79 => 230,
        80 => 240, 81 => 251, 82 => 262, 83 => 273, 84 => 284, 85 => 296, 86 => 309, 87 => 322, 88 => 336, 89 => 351,
        90 => 366, 91 => 383, 92 => 401, 93 => 422, 94 => 444, 95 => 470, 96 => 501, 97 => 538, 98 => 589, 99 => 677, 100 => 800,
    ];

    public function metric(): StandingsMetric
    {
        return StandingsMetric::PerformanceRating;
    }

    public function isImplemented(StandardScoringSettings $settings): bool
    {
        return $settings->tprMethod()->isImplemented();
    }

    public function calculate(ScoringContext $context): array
    {
        $tpr = [];

        foreach ($context->playerIds as $id) {
            $games        = $context->gamesByPlayer[$id] ?? [];
            $played       = count($games);
            $tpr[$id]     = 0.0;

            if ($played === 0) {
                continue;
            }

            $score  = 0.0;
            $sumOpp = 0;
            foreach ($games as $game) {
                $score  += $this->gamePoints($game['outcome']);
                $sumOpp += $context->ratings[$game['opponent']] ?? 0;
            }

            $avgOpp  = $sumOpp / $played;
            $percent = (int)round(($score / $played) * 100);

            $tpr[$id] = $avgOpp + $this->dp($percent);
        }

        return $tpr;
    }

    private function gamePoints(ScoringOutcome $outcome): float
    {
        return match ($outcome) {
            ScoringOutcome::Win  => 1.0,
            ScoringOutcome::Draw => 0.5,
            ScoringOutcome::Loss => 0.0,
        };
    }

    private function dp(int $percent): int
    {
        $percent = max(0, min(100, $percent));

        return $percent >= 50 ? self::DP[$percent] : -self::DP[100 - $percent];
    }
}
