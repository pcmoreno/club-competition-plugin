<?php

declare(strict_types=1);

namespace SCS\Engine\Scoring;

use SCS\Engine\Scoring\Metric\MetricCalculatorInterface;
use SCS\Entity\Enum\ScoringOutcome;
use SCS\Entity\Enum\StandingsMetric;

// Pass 1: compute the structural counts and run every implemented metric calculator into one map per player.
final class PlayerScoreCalculator
{
    /** @param iterable<MetricCalculatorInterface> $calculators */
    public function __construct(private readonly iterable $calculators)
    {
    }

    /** @return list<PlayerScore> */
    public function calculate(ScoringContext $context): array
    {
        // Points first — the points-dependent calculators (SB, Buchholz) read it from the context.
        $points  = (new Metric\PointsCalculator())->calculate($context);
        $context = $context->withPoints($points);

        $columns = [];
        foreach ($this->calculators as $calculator) {
            if ($calculator->isImplemented($context->settings)) {
                $columns[$calculator->metric()->value] = $calculator->calculate($context);
            }
        }

        $scores = [];
        foreach ($context->playerIds as $id) {
            $structural = $this->structuralCounts($context, $id);

            $metrics = [];
            foreach ($columns as $metric => $values) {
                $metrics[$metric] = $values[$id] ?? 0.0;
            }

            // Points always present even if not a registered calculator; keep the classical field aligned.
            $metrics[StandingsMetric::Points->value] = $points[$id] ?? 0.0;

            $scores[] = new PlayerScore($id, $structural + $metrics);
        }

        return $scores;
    }

    /** @return array<string,int> */
    private function structuralCounts(ScoringContext $context, int $id): array
    {
        $wins = $draws = $losses = $white = $black = 0;

        foreach ($context->gamesByPlayer[$id] ?? [] as $game) {
            match ($game['outcome']) {
                ScoringOutcome::Win  => $wins++,
                ScoringOutcome::Draw => $draws++,
                ScoringOutcome::Loss => $losses++,
            };
            $game['white'] ? $white++ : $black++;
        }

        $byes = count($context->byesByPlayer[$id] ?? []);

        return [
            'games'         => $wins + $draws + $losses,
            'wins'          => $wins,
            'draws'         => $draws,
            'losses'        => $losses,
            'byes'          => $byes,
            'color_balance' => $white - $black,
        ];
    }
}
