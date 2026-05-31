<?php

declare(strict_types=1);

namespace SCS\Entity;

/**
 * A player's standing frozen at the moment a round completed — the official
 * record after that round. Immutable once written (an already-complete round
 * can't be edited). Engine changes affect only future snapshots, never these.
 */
class StandingsSnapshot
{
    public function __construct(
        public readonly int $id,
        public readonly int $season_id,
        public readonly int $round_id,
        public readonly int $season_player_id,
        public readonly int $rank,
        public readonly int $keizer_score,
        public readonly float $classical_points,
        public readonly int $wins,
        public readonly int $draws,
        public readonly int $losses,
        public readonly int $games,
        public readonly int $byes,
        public readonly int $color_balance,
        public readonly ?int $tpr,
    ) {
    }
}
