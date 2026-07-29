<?php

declare(strict_types=1);

namespace SCS\Entity;

/**
 * A player's standing frozen at the moment a round completed — the official
 * record after that round. Engine changes affect only future snapshots, never
 * these.
 *
 * Not immutable, but rewritten only through one path: results and attendance
 * are locked while a round is complete, so changing the record means reopening
 * the round (RoundService::reopenRound) and completing it again. That rewrites
 * this round's snapshots and every later completed round's, because standings
 * accumulate.
 */
class StandingsSnapshot
{
    /** @param array<string,mixed> $scores */
    public function __construct(
        public readonly int $id,
        public readonly int $season_id,
        public readonly int $round_id,
        public readonly int $season_player_id,
        public readonly int $rank,
        // Null for seasons that don't rank by a Keizer value (they rank by
        // classical_points instead); see migration 0006.
        public readonly ?int $keizer_score,
        public readonly float $classical_points,
        public readonly int $wins,
        public readonly int $draws,
        public readonly int $losses,
        public readonly int $games,
        public readonly int $byes,
        public readonly int $color_balance,
        public readonly ?int $tpr,
        // Open map of every computed metric (metric value => score); display picks a subset.
        public readonly array $scores = [],
    ) {
    }
}
