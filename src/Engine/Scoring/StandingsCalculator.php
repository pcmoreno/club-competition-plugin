<?php

declare(strict_types=1);

namespace SCS\Engine\Scoring;

use SCS\Entity\Enum\StandingsMetric;

// Pass 2: order players by rankBy then the tiebreakers, and assign ranks (shared on a true tie).
final class StandingsCalculator
{
    /**
     * @param list<PlayerScore> $playerScores
     * @return list<array{score:PlayerScore,rank:int}>
     */
    public function rank(array $playerScores, ScoringContext $context): array
    {
        $settings = $context->settings;
        $criteria = array_merge([$settings->rankBy], $settings->tiebreakers);

        $ordered = $this->resolve($playerScores, $criteria, $context);

        // Rank sharing uses the column criteria only — DirectEncounter orders but doesn't split a rank.
        $columnCriteria = array_values(array_filter(
            $criteria,
            static fn (StandingsMetric $m) => $m !== StandingsMetric::DirectEncounter
        ));

        $result  = [];
        $prevKey = null;
        $rank    = 0;
        foreach ($ordered as $i => $score) {
            $key = $this->rankKey($score, $columnCriteria);
            if ($key !== $prevKey) {
                $rank    = $i + 1;
                $prevKey = $key;
            }
            $result[] = ['score' => $score, 'rank' => $rank];
        }

        return $result;
    }

    /**
     * @param list<PlayerScore>     $players
     * @param list<StandingsMetric> $criteria
     * @return list<PlayerScore>
     */
    private function resolve(array $players, array $criteria, ScoringContext $context): array
    {
        if (count($players) <= 1 || $criteria === []) {
            return $players;
        }

        $head = $criteria[0];
        $rest = array_slice($criteria, 1);

        if ($head === StandingsMetric::DirectEncounter) {
            if (count($players) > $context->settings->directEncounterMaxGroup()) {
                return $this->resolve($players, $rest, $context); // group too big — skip DE
            }

            $ids   = array_map(static fn (PlayerScore $p) => $p->seasonPlayerId, $players);
            $mini  = [];
            foreach ($players as $p) {
                $mini[$p->seasonPlayerId] = $this->miniLeaguePoints($p->seasonPlayerId, $ids, $context);
            }

            usort($players, static fn (PlayerScore $a, PlayerScore $b) => $mini[$b->seasonPlayerId] <=> $mini[$a->seasonPlayerId]);

            return $this->regroup($players, $rest, $context, static fn (PlayerScore $p) => $mini[$p->seasonPlayerId]);
        }

        usort($players, static fn (PlayerScore $a, PlayerScore $b) => $b->metric($head) <=> $a->metric($head));

        return $this->regroup($players, $rest, $context, static fn (PlayerScore $p) => $p->metric($head));
    }

    /**
     * Recurse the remaining criteria into each still-tied run of equal values.
     *
     * @param list<PlayerScore>     $players
     * @param list<StandingsMetric> $rest
     * @return list<PlayerScore>
     */
    private function regroup(array $players, array $rest, ScoringContext $context, callable $value): array
    {
        $out   = [];
        $group = [];
        $prev  = null;

        foreach ($players as $player) {
            $current = $value($player);
            if ($group !== [] && $current !== $prev) {
                $out = array_merge($out, $this->resolve($group, $rest, $context));
                $group = [];
            }
            $group[] = $player;
            $prev    = $current;
        }

        return array_merge($out, $this->resolve($group, $rest, $context));
    }

    /** @param list<int> $groupIds */
    private function miniLeaguePoints(int $id, array $groupIds, ScoringContext $context): float
    {
        $set   = array_flip($groupIds);
        $total = 0.0;
        foreach ($context->gamesByPlayer[$id] ?? [] as $game) {
            if (isset($set[$game['opponent']])) {
                $total += $context->settings->pointsFor($game['outcome']);
            }
        }

        return $total;
    }

    /** @param list<StandingsMetric> $columnCriteria */
    private function rankKey(PlayerScore $score, array $columnCriteria): string
    {
        return implode('|', array_map(
            static fn (StandingsMetric $m) => number_format($score->metric($m), 4, '.', ''),
            $columnCriteria
        ));
    }
}
