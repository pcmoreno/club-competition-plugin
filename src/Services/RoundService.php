<?php

declare(strict_types=1);

namespace SCS\Services;

use SCS\Engine\ScoringStrategyResolver;
use SCS\Entity\Round;
use SCS\Exception\NotFoundException;
use SCS\Repository\AttendanceRepository;
use SCS\Repository\GameRepository;
use SCS\Repository\RoundRepository;
use SCS\Repository\SeasonPlayerRepository;
use SCS\Repository\SeasonRepository;
use SCS\Repository\StandingsSnapshotRepository;

// Orchestrates round completion: run the season's scoring strategy and persist the standings snapshot.
final class RoundService
{
    public function __construct(
        private readonly ScoringStrategyResolver $scoringResolver,
        private readonly SeasonRepository $seasons,
        private readonly SeasonPlayerRepository $seasonPlayers,
        private readonly RoundRepository $rounds,
        private readonly GameRepository $games,
        private readonly AttendanceRepository $attendance,
        private readonly StandingsSnapshotRepository $snapshots,
    ) {
    }

    public function completeRound(Round $round): void
    {
        $season = $this->seasons->findById($round->season_id);
        if ($season === null) {
            throw new NotFoundException('Season not found for round.');
        }

        /** @var list<\SCS\Entity\SeasonPlayer> $roster */
        $roster = $this->seasonPlayers->findBySeason($season->id);

        // Standard scoring is cumulative over all completed games/attendance up to this round.
        /** @var list<\SCS\Entity\Game> $games */
        $games = [];
        /** @var list<\SCS\Entity\Attendance> $attendance */
        $attendance = [];
        foreach ($this->rounds->findBySeason($season->id) as $r) {
            if ($r->round_number > $round->round_number) {
                continue;
            }
            $games      = array_merge($games, $this->games->findByRound($r->id));
            $attendance = array_merge($attendance, $this->attendance->findByRound($r->id));
        }

        $strategy  = $this->scoringResolver->resolve($season);
        $snapshots = $strategy->computeStandings($season, $round, $roster, $games, $attendance);

        // Write-once per round; rewrite on a re-completion (the un-complete edge case).
        $this->snapshots->deleteByRound($round->id);
        foreach ($snapshots as $snapshot) {
            $this->snapshots->create(
                $snapshot->season_id,
                $snapshot->round_id,
                $snapshot->season_player_id,
                $snapshot->rank,
                $snapshot->keizer_score,
                $snapshot->classical_points,
                $snapshot->wins,
                $snapshot->draws,
                $snapshot->losses,
                $snapshot->games,
                $snapshot->byes,
                $snapshot->color_balance,
                $snapshot->tpr,
                $snapshot->scores,
            );
        }
    }
}
