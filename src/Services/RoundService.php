<?php

declare(strict_types=1);

namespace SCS\Services;

use SCS\Engine\Pairing\FullSchedulePairing;
use SCS\Engine\PairingEngineResolver;
use SCS\Engine\ScoringStrategyResolver;
use SCS\Engine\SettingsResolver;
use SCS\Entity\Enum\AttendanceStatus;
use SCS\Entity\Enum\ByeType;
use SCS\Entity\Enum\GameResult;
use SCS\Entity\Enum\RoundStatus;
use SCS\Entity\Game;
use SCS\Entity\Round;
use SCS\Entity\Season;
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
        private readonly PairingEngineResolver $pairingEngines,
        private readonly SettingsResolver $settings,
    ) {
    }

    /**
     * Append a round to a season that pairs one at a time.
     *
     * A full-schedule tournament's rounds come from its generated fixture, so an
     * extra one would sit outside the schedule. Before there is a schedule the
     * manual path stays open, so a failed generation can never leave the admin
     * with no way to create a round at all.
     */
    public function createRound(Season $season, ?string $date): Round
    {
        if ($season->pairing_system->cadence() === 'full' && $this->rounds->findBySeason($season->id) !== []) {
            throw new ConflictException('This tournament’s rounds come from its generated schedule.');
        }

        return $this->rounds->createNextForSeason(
            season_id: $season->id,
            date:      $date,
            maxRounds: $this->settings->roundLimit($season),
        );
    }

    /**
     * Build a full-schedule tournament's entire fixture in one go, as a run of
     * draft rounds.
     *
     * Regenerating is only allowed while every round is still draft. The whole
     * schedule is derived from pairing numbers, so rebuilding it rewrites boards
     * from round 1 — harmless before anyone has seen them, and unacceptable once
     * a round is published. The roster is effectively locked from here on for the
     * same reason: a late enrolment shifts every number after it.
     *
     * @return list<Round>
     */
    public function generateSchedule(Season $season): array
    {
        $engine = $this->pairingEngines->resolve($season);
        if (!$engine instanceof FullSchedulePairing) {
            throw new ConflictException('This tournament pairs one round at a time, not as a whole schedule.');
        }

        $existing = $this->rounds->findBySeason($season->id);
        foreach ($existing as $round) {
            if ($round->status !== RoundStatus::Draft) {
                throw new ConflictException(sprintf(
                    'Round %d is already %s, so the schedule can no longer be generated.',
                    $round->round_number,
                    $round->status->value
                ));
            }
        }

        /** @var list<\SCS\Entity\SeasonPlayer> $roster */
        $roster = $this->seasonPlayers->findBySeason($season->id);

        // Outside the transaction: the engine is pure, and its guards (too few
        // players, too many rounds) should fail before anything is deleted.
        $schedule = $engine->pairSchedule($season, $roster);

        return $this->transactions->transactional(function () use ($season, $existing, $schedule): array {
            if ($existing !== []) {
                // No FK cascade, so clear the child rows first — snapshots
                // included, or they outlive the round ids they point at and the
                // read paths' inner join hides them.
                $this->snapshots->deleteBySeason($season->id);
                $this->games->deleteBySeason($season->id);
                $this->attendance->deleteBySeason($season->id);
                $this->rounds->deleteBySeason($season->id);
            }

            $created = [];
            foreach ($schedule as $index => $result) {
                $round = $this->rounds->create($season->id, $index + 1, null, RoundStatus::Draft);

                foreach ($result->pairings as $pairing) {
                    $this->games->create(
                        $round->id,
                        $pairing['white'],
                        $pairing['black'],
                        $pairing['board'],
                        null,
                        $season->time_control,
                    );
                }

                // The odd player out is present, not absent — a pairing bye is
                // "here, but nobody to play", which is what scoring prices.
                // Saved one at a time rather than through saveMany, which opens
                // a transaction of its own and would nest inside this one.
                foreach ($result->byes as $bye) {
                    $this->attendance->save(
                        $round->id,
                        $bye['season_player_id'],
                        AttendanceStatus::Present,
                        ByeType::from($bye['bye_type']),
                    );
                }

                $created[] = $round;
            }

            return $created;
        });
    }

    public function addPairing(int $roundId, int $white, int $black, ?int $board): Game
    {
        $round = $this->requireEditableRound($this->rounds->findById($roundId));
        $this->assertPairingValid($round, $white, $black, null);

        // The game inherits the tournament's tempo, fixed at the moment it is
        // paired: changing the season's time control later must not rewrite the
        // games already played under the old one.
        $season = $this->seasons->findById($round->season_id);
        if ($season === null) {
            throw new NotFoundException('Season not found for round.');
        }

        return $this->games->create(
            $round->id,
            $white,
            $black,
            $board ?? $this->nextBoard($round->id),
            null,
            $season->time_control,
        );
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

    /**
     * Freeze the standings for a round, and for every later round already
     * completed.
     *
     * Standings are cumulative, so a correction in round 3 also moves rounds 4
     * and 5. Recomputing only the round being completed is what made a
     * post-completion result edit desync the games from the snapshots
     * permanently.
     */
    public function completeRound(Round $round): void
    {
        $season = $this->seasons->findById($round->season_id);
        if ($season === null) {
            throw new NotFoundException('Season not found for round.');
        }

        /** @var list<\SCS\Entity\SeasonPlayer> $roster */
        $roster = $this->seasonPlayers->findBySeason($season->id);

        /** @var list<Round> $seasonRounds */
        $seasonRounds = $this->rounds->findBySeason($season->id);

        // This round plus every later completed one, oldest first.
        $targets = array_values(array_filter(
            $seasonRounds,
            static fn (Round $r) => $r->id === $round->id
                || ($r->round_number > $round->round_number && $r->status === RoundStatus::Complete)
        ));
        usort($targets, static fn (Round $a, Round $b) => $a->round_number <=> $b->round_number);

        $strategy = $this->scoringResolver->resolve($season);

        // One transaction over the whole rewrite. Each round's delete and its
        // per-player inserts are a unit — a failure part-way through would
        // leave a partial standings table (some players, ranks with holes), and
        // findLatestForSeason picks the highest round_number with *any*
        // snapshot, so that fragment would become the published standings.
        // Keizer prices every round against the one before it, so the cascade
        // has to hand each target the ranking it steps forward from. Rounds are
        // rewritten oldest first, so a target's predecessor is either one we
        // just computed here or the stored snapshot of a round we aren't
        // touching.
        $computed = [];

        $this->transactions->transactional(function () use ($season, $roster, $seasonRounds, $targets, $strategy, &$computed): void {
            foreach ($targets as $target) {
                // Standard scoring is cumulative over all games/attendance up to this round.
                /** @var list<\SCS\Entity\Game> $games */
                $games = [];
                /** @var list<\SCS\Entity\Attendance> $attendance */
                $attendance = [];
                foreach ($seasonRounds as $r) {
                    if ($r->round_number > $target->round_number) {
                        continue;
                    }
                    $games      = array_merge($games, $this->games->findByRound($r->id));
                    $attendance = array_merge($attendance, $this->attendance->findByRound($r->id));
                }

                $previousRound = $this->roundBefore($seasonRounds, $target);
                $previous      = $previousRound === null
                    ? []
                    : array_values($computed[$previousRound->id] ?? $this->snapshots->findByRound($previousRound->id));

                $snapshots            = $strategy->computeStandings($season, $target, $roster, $games, $attendance, $previous);
                $computed[$target->id] = $snapshots;

                $this->snapshots->deleteByRound($target->id);
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
        });
    }

    /**
     * Record a game result. Guarded on the round's status: a completed round's
     * standings are frozen, so editing a result there would leave the games and
     * the snapshot disagreeing with nothing to reconcile them.
     */
    public function updateGameResult(int $gameId, ?GameResult $result): Game
    {
        $game = $this->requireGame($gameId);
        $this->requireScorableRound($this->rounds->findById($game->round_id));

        $this->games->updateResult($game->id, $result);

        return $this->requireGame($game->id);
    }

    /**
     * Save a round's attendance. Same status guard as results, and the same
     * reason: attendance feeds byes, which feed the frozen standings.
     *
     * @param list<array{season_player_id:int,status:AttendanceStatus,bye_type:?ByeType}> $entries
     */
    public function saveAttendance(int $roundId, array $entries): void
    {
        $round = $this->requireScorableRound($this->rounds->findById($roundId));

        // Attendance must belong to this round's season; assertPairingValid
        // makes the same check for pairings.
        $roster = array_column($this->seasonPlayers->findBySeason($round->season_id), 'id');
        foreach ($entries as $entry) {
            if (!in_array($entry['season_player_id'], $roster, true)) {
                throw new ValidationException(['attendance' => 'Every player must be enrolled in this season.']);
            }
        }

        $this->attendance->saveMany($round->id, $entries);
    }

    /**
     * Reopen a completed round so a wrong result can be corrected.
     *
     * The snapshot is deliberately left in place: it's the last known-good
     * standings, and dropping it would blank the public table for as long as
     * the round stays open. Re-completing overwrites it, along with every later
     * completed round's.
     */
    public function reopenRound(Round $round): void
    {
        if ($round->status !== RoundStatus::Complete) {
            throw new ConflictException('Only a completed round can be reopened.');
        }

        $this->rounds->updateStatus($round->id, RoundStatus::Finalised);
    }

    /**
     * The round immediately before this one by number, which is not simply the
     * previous array element — a season can have gaps once rounds are deleted.
     *
     * @param list<Round> $rounds
     */
    private function roundBefore(array $rounds, Round $target): ?Round
    {
        $previous = null;
        foreach ($rounds as $round) {
            if ($round->round_number >= $target->round_number) {
                continue;
            }
            if ($previous === null || $round->round_number > $previous->round_number) {
                $previous = $round;
            }
        }

        return $previous;
    }

    private function requireGame(int $gameId): Game
    {
        $game = $this->games->findById($gameId);
        if ($game === null) {
            throw new NotFoundException('Game not found.');
        }

        return $game;
    }

    /**
     * Results and attendance are writable until the round is completed, which
     * freezes its standings snapshot. Reopening the round (see reopenRound)
     * is the supported way to correct one afterwards.
     */
    private function requireScorableRound(?Round $round): Round
    {
        if ($round === null) {
            throw new NotFoundException('Round not found.');
        }
        if ($round->status === RoundStatus::Complete) {
            throw new ConflictException(
                'This round is complete and its standings are frozen. Reopen the round to correct a result.'
            );
        }

        return $round;
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
