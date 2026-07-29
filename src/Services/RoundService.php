<?php

declare(strict_types=1);

namespace SCS\Services;

use SCS\Engine\ScoringStrategyResolver;
use SCS\Entity\Enum\RoundStatus;
use SCS\Entity\Game;
use SCS\Entity\Round;
use SCS\Exception\ConflictException;
use SCS\Exception\NotFoundException;
use SCS\Exception\ValidationException;
use SCS\Repository\AttendanceRepository;
use SCS\Repository\GameRepository;
use SCS\Repository\RoundRepository;
use SCS\Repository\SeasonPlayerRepository;
use SCS\Repository\SeasonRepository;
use SCS\Repository\StandingsSnapshotRepository;

// Orchestrates rounds: manual pairing changes, and round completion (scoring strategy + standings snapshot).
final class RoundService
{
    public function __construct(
        private readonly ScoringStrategyResolver $scoringResolver,
        private readonly TransactionManager $transactions,
        private readonly SeasonRepository $seasons,
        private readonly SeasonPlayerRepository $seasonPlayers,
        private readonly RoundRepository $rounds,
        private readonly GameRepository $games,
        private readonly AttendanceRepository $attendance,
        private readonly StandingsSnapshotRepository $snapshots,
    ) {
    }

    public function addPairing(int $roundId, int $white, int $black, ?int $board): Game
    {
        $round = $this->requireEditableRound($this->rounds->findById($roundId));
        $this->assertPairingValid($round, $white, $black, null);

        return $this->games->create($round->id, $white, $black, $board ?? $this->nextBoard($round->id));
    }

    public function updatePairing(int $gameId, ?int $white, ?int $black, ?int $board): Game
    {
        $game  = $this->requireGame($gameId);
        $round = $this->requireEditableRound($this->rounds->findById($game->round_id));

        $white ??= $game->white_season_player_id;
        $black ??= $game->black_season_player_id;
        $this->assertPairingValid($round, $white, $black, $game->id);

        $data = [
            'white_season_player_id' => $white,
            'black_season_player_id' => $black,
        ];
        if ($board !== null) {
            $data['board'] = $board;
        }
        $this->games->update($game->id, $data);

        return $this->requireGame($game->id);
    }

    public function removePairing(int $gameId): void
    {
        $game = $this->requireGame($gameId);
        $this->requireEditableRound($this->rounds->findById($game->round_id));

        $this->games->delete($game->id);
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
        //
        // Transactional because the delete and the per-player inserts are one
        // unit: a failure part-way through would otherwise leave the round with
        // a partial standings table (some players, ranks with holes), and
        // findLatestForSeason picks the highest round_number that has *any*
        // snapshot, so that fragment would become the published standings.
        $this->transactions->transactional(function () use ($round, $snapshots): void {
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
        });
    }

    private function requireGame(int $gameId): Game
    {
        $game = $this->games->findById($gameId);
        if ($game === null) {
            throw new NotFoundException('Game not found.');
        }

        return $game;
    }

    // Pairings are editable while draft/published; finalised and complete are locked.
    private function requireEditableRound(?Round $round): Round
    {
        if ($round === null) {
            throw new NotFoundException('Round not found.');
        }
        if ($round->status === RoundStatus::Finalised || $round->status === RoundStatus::Complete) {
            throw new ConflictException('Pairings are locked once the round is finalised.');
        }

        return $round;
    }

    private function assertPairingValid(Round $round, int $white, int $black, ?int $excludeGameId): void
    {
        if ($white === $black) {
            throw new ValidationException(['players' => 'A player cannot be paired against themselves.']);
        }

        $roster = array_column($this->seasonPlayers->findBySeason($round->season_id), 'id');

        if (!in_array($white, $roster, true) || !in_array($black, $roster, true)) {
            throw new ValidationException(['players' => 'Both players must be enrolled in this season.']);
        }

        foreach ($this->games->findByRound($round->id) as $existing) {
            if ($existing->id === $excludeGameId) {
                continue;
            }
            $paired = [$existing->white_season_player_id, $existing->black_season_player_id];
            if (in_array($white, $paired, true) || in_array($black, $paired, true)) {
                throw new ConflictException('A player is already paired in this round.');
            }
        }
    }

    private function nextBoard(int $roundId): int
    {
        $max = 0;
        foreach ($this->games->findByRound($roundId) as $game) {
            $max = max($max, $game->board ?? 0);
        }

        return $max + 1;
    }
}
