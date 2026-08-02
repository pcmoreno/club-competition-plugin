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
            // Null, not 0.0: a TPR that can't be computed is unknown, and the
            // standings render null as an em dash rather than a bottom rank.
            $tpr[$id] = null;

            $score  = 0.0;
            $sumOpp = 0;
            $rated  = 0;
            foreach ($context->gamesByPlayer[$id] ?? [] as $game) {
                // elo_rating is NOT NULL DEFAULT 0, so an unrated player is stored as 0,
                // and a missing key is an opponent outside this season's roster. Counting
                // either as a 0-rated opponent drags the average down by hundreds of points.
                $rating = $context->ratings[$game['opponent']] ?? 0;
                if ($rating <= 0) {
                    continue;
                }

                $score  += $this->gamePoints($game['outcome']);
                $sumOpp += $rating;
                $rated++;
            }

            if ($rated === 0) {
                continue;
            }

            // Both halves count rated games only — FIDE computes the performance
            // against rated opposition, so a win over an unrated player can't lift p either.
            $avgOpp  = $sumOpp / $rated;
            $percent = (int)round(($score / $rated) * 100);

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
