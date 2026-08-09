<?php

declare(strict_types=1);

namespace SCS\Engine\Scoring\Keizer;

use SCS\Engine\Settings\KeizerScoringSettings;
use SCS\Entity\Enum\ValuationMethod;

/**
 * Turns a ranking into the values its players are worth.
 *
 * Position range: the top of the ranking takes the top value, the bottom takes
 * the bottom value, and everyone else is spread evenly between them. The step
 * is therefore a fraction — 200 down to 100 across fifty players moves about
 * two points a rung — and rounding is what lands it on whole numbers.
 *
 * The ladder is built over the whole enrolled roster, not just the players who
 * have appeared. That is what the club's own history computes back to, and it
 * matters: values depend on how many rungs there are, so counting only the
 * players who have turned up would move everyone's value every time a new
 * member played their first game.
 */
final class ValueLadder
{
    /**
     * @param  list<int>         $rankedSeasonPlayerIds best first
     * @return array<int,float>  season_player_id => value
     */
    public function build(array $rankedSeasonPlayerIds, KeizerScoringSettings $settings): array
    {
        $count = count($rankedSeasonPlayerIds);
        if ($count === 0) {
            return [];
        }

        $decimals   = $settings->valueDecimals();
        $multiplier = (float)$settings->valueMultiplier();

        $values = [];
        foreach ($rankedSeasonPlayerIds as $position => $seasonPlayerId) {
            $value = $multiplier * $this->rungValue($position, $count, $settings);

            $values[$seasonPlayerId] = $decimals === null ? $value : round($value, $decimals);
        }

        return $values;
    }

    /**
     * What the rung at this position is worth, before the multiplier.
     *
     * Position range spreads the whole field between the two configured values,
     * so its step depends on how many players there are — add a member and
     * everyone's value shifts slightly. The stepped methods instead walk a fixed
     * amount per group of rungs, which keeps a player's value stable as the
     * field grows but lets the bottom of a large field fall a long way, or
     * below zero.
     */
    private function rungValue(int $position, int $count, KeizerScoringSettings $settings): float
    {
        $top    = (float)$settings->topValue();
        $bottom = (float)$settings->bottomValue();
        $method = $settings->valuation();

        if (!$method->usesStep()) {
            // A one-player ladder has no spread to divide, and its single rung
            // is the top one.
            $step = $count > 1 ? ($top - $bottom) / ($count - 1) : 0.0;

            return $top - $step * $position;
        }

        $rung = intdiv($position, max(1, $settings->valueStepEvery()));
        $step = (float)$settings->valueStep();

        if ($method === ValuationMethod::PositionFromBottom) {
            $fromBottom = intdiv($count - 1 - $position, max(1, $settings->valueStepEvery()));

            return $bottom + $step * $fromBottom;
        }

        return $top - $step * $rung;
    }
}
