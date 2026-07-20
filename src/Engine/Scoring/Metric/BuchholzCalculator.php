<?php

declare(strict_types=1);

namespace SCS\Engine\Scoring\Metric;

use SCS\Engine\Scoring\ScoringContext;
use SCS\Engine\Settings\StandardScoringSettings;
use SCS\Entity\Enum\BuchholzMethod;
use SCS\Entity\Enum\StandingsMetric;

// Sum of opponents' points. Only the Classic method is implemented; other methods return 0 (stub).
final class BuchholzCalculator implements MetricCalculatorInterface
{
    public function metric(): StandingsMetric
    {
        return StandingsMetric::Buchholz;
    }

    public function isImplemented(StandardScoringSettings $settings): bool
    {
        return $settings->buchholzMethod() === BuchholzMethod::Classic;
    }

    public function calculate(ScoringContext $context): array
    {
        $buchholz = [];

        foreach ($context->playerIds as $id) {
            $total = 0.0;

            if ($context->settings->buchholzMethod() === BuchholzMethod::Classic) {
                foreach ($context->gamesByPlayer[$id] ?? [] as $game) {
                    $total += $context->points[$game['opponent']] ?? 0.0;
                }
            }

            $buchholz[$id] = $total;
        }

        return $buchholz;
    }
}
