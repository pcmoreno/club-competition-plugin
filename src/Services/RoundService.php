<?php

declare(strict_types=1);

namespace SCS\Services;

use SCS\Engine\Pairing\FullSchedulePairing;
use SCS\Engine\Pairing\PerRoundPairing;
use SCS\Engine\PairingEngineResolver;
use SCS\Engine\ScoringStrategyResolver;
use SCS\Engine\SettingsResolver;
use SCS\Entity\Enum\AttendanceStatus;
use SCS\Entity\Enum\ByeType;
use SCS\Entity\Enum\GameResult;
use SCS\Entity\Enum\RoundStatus;
use SCS\Entity\Enum\SeasonStatus;
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

    // Append a round to a season that pairs one at a time.
    public function createRound(Season $season, ?string $date): Round
    {
        $this->assertSeasonNotCompleted($season);

        // A full schedule is the round set: every round comes from the fixture,
        // so there is no such thing as one made by hand.
        if ($season->pairing_system->cadence() === 'full') {
            throw new ConflictException('This tournament’s rounds come from its generated schedule.');
        }

        return $this->transactions->transactional(function () use ($season, $date): Round {
            $this->lockOpenSeason($season->id);

            $round = $this->rounds->createNextForSeason(
                season_id: $season->id,
                date:      $date,
                maxRounds: $this->settings->roundLimit($season),
            );

            // The only trigger: flagging an enrolment later doesn't backfill rounds that already exist.
            foreach ($this->seasonPlayers->findBySeason($season->id) as $enrolment) {
                if ($enrolment->default_absent) {
                    $this->attendance->save($round->id, $enrolment->id, AttendanceStatus::Absent, ByeType::Personal);
                }
            }

            return $round;
        });
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
        $this->assertSeasonNotCompleted($season);

        $engine = $this->pairingEngines->resolve($season);
        if (!$engine instanceof FullSchedulePairing) {
            throw new ConflictException('This tournament pairs one round at a time, not as a whole schedule.');
        }

        /** @var list<\SCS\Entity\SeasonPlayer> $roster */
        $roster = $this->seasonPlayers->findBySeason($season->id);

        // Outside the transaction: the engine is pure, and its guards (too few
        // players, too many rounds) should fail before anything is deleted.
        $schedule = $engine->pairSchedule($season, $roster);

        return $this->transactions->transactional(function () use ($season, $schedule): array {
            $this->lockOpenSeason($season->id);

            // Read under the lock, and delete exactly what was read. Deciding
            // from an earlier read would destroy rounds it never examined —
            // one published in between takes its games and snapshots with it.
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

            if ($existing !== []) {
                $roundIds = array_values(array_map(static fn (Round $r): int => $r->id, $existing));

                // No FK cascade, so clear the child rows first — snapshots
                // included, or they outlive the round ids they point at and the
                // read paths' inner join hides them.
                $this->snapshots->deleteByRounds($roundIds);
                $this->games->deleteByRounds($roundIds);
                $this->attendance->deleteByRounds($roundIds);
                $this->rounds->deleteByIds($roundIds);
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

    /**
     * Build one round's boards from the standings.
     *
     * The counterpart to generateSchedule for systems that pair a round at a
     * time. Only players who are actually present are paired: an absence
     * recorded before the board is built takes that player out of it, which is
     * the whole reason the attendance window comes first.
     *
     * Refused if the round already has games. Regenerating over a board an
     * admin has adjusted by hand would silently discard that work, and clearing
     * the round first is an explicit act.
     *
     * Also refused unless every earlier round is complete — see
     * requireStandingsAreCurrent.
     *
     * @return list<Game>
     */
    public function pairRound(Round $round): array
    {
        $this->requireEditableRound($round);

        $season = $this->seasons->findById($round->season_id);
        if ($season === null) {
            throw new NotFoundException('Season not found for round.');
        }

        $engine = $this->pairingEngines->resolve($season);
        if (!$engine instanceof PerRoundPairing) {
            throw new ConflictException('This tournament lays out its whole schedule at once, not a round at a time.');
        }

        if ($this->games->findByRound($round->id) !== []) {
            throw new ConflictException('This round already has pairings. Remove them before generating new ones.');
        }

        $this->requireStandingsAreCurrent($round);

        $result = $engine->pairNextRound(
            $season,
            $this->presentPlayers($round),
            $this->gamesBefore($season->id, $round),
            array_values($this->snapshots->findLatestForSeason($season->id)),
        );

        return $this->transactions->transactional(function () use ($round, $season, $result): array {
            // The bye is an attendance row, not a board, so deleting the
            // pairings to regenerate leaves it behind, and save() upserts one
            // player rather than replacing the round's set. Scoring reads games
            // and byes from separate tables, so a holder who ends up on a board
            // this time is priced for both.
            $this->attendance->deleteByRoundAndByeType($round->id, ByeType::PairingBye);

            $games = [];
            foreach ($result->pairings as $pairing) {
                $games[] = $this->games->create(
                    $round->id,
                    $pairing['white'],
                    $pairing['black'],
                    $pairing['board'],
                    null,
                    $season->time_control,
                );
            }

            // Saved one at a time rather than through saveMany, which opens a
            // transaction of its own and would nest inside this one.
            foreach ($result->byes as $bye) {
                $this->attendance->save(
                    $round->id,
                    $bye['season_player_id'],
                    AttendanceStatus::Present,
                    ByeType::from($bye['bye_type']),
                );
            }

            return $games;
        });
    }

    /**
     * A round may only be generated once every round before it is complete.
     *
     * Everything a per-round engine reads is written by completeRound: the
     * standings it pairs from, and the bye counts it rations the pairing bye by.
     * Pair ahead of completion and both are frozen at some earlier round — the
     * ranking is visibly stale, but the bye counts are not, so the same player
     * can sit out twice running while the rule that forbids it reads as
     * satisfied.
     *
     * Only generation is gated. Building a future board by hand stays open,
     * which is the way to lay out a round early, and the way past a round that
     * can't be completed.
     */
    private function requireStandingsAreCurrent(Round $round): void
    {
        $blocking = null;
        foreach ($this->rounds->findBySeason($round->season_id) as $earlier) {
            if ($earlier->round_number >= $round->round_number || $earlier->status === RoundStatus::Complete) {
                continue;
            }
            $blocking = min($blocking ?? PHP_INT_MAX, $earlier->round_number);
        }

        if ($blocking !== null) {
            throw new ConflictException(sprintf(
                'Round %d has to be completed before round %d can be generated — the pairing reads the standings it writes.',
                $blocking,
                $round->round_number
            ));
        }
    }

    /**
     * The roster minus anyone recorded absent for this round.
     *
     * Absence is opt-out: a player with no attendance row at all is assumed to
     * be coming, because the club pairs on the expectation that people turn up
     * and only hears about the ones who can't.
     *
     * @return list<\SCS\Entity\SeasonPlayer>
     */
    private function presentPlayers(Round $round): array
    {
        $absent = [];
        foreach ($this->attendance->findByRound($round->id) as $row) {
            if ($row->status === AttendanceStatus::Absent) {
                $absent[$row->season_player_id] = true;
            }
        }

        $present = [];
        foreach ($this->seasonPlayers->findBySeason($round->season_id) as $player) {
            if (!isset($absent[$player->id])) {
                $present[] = $player;
            }
        }

        return $present;
    }

    /**
     * Every game played in earlier rounds, for colour history and rematches.
     *
     * @return list<Game>
     */
    private function gamesBefore(int $seasonId, Round $round): array
    {
        $games = [];
        foreach ($this->rounds->findBySeason($seasonId) as $earlier) {
            if ($earlier->round_number >= $round->round_number) {
                continue;
            }
            $games = array_merge($games, $this->games->findByRound($earlier->id));
        }

        return $games;
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

        return $this->transactions->transactional(function () use ($round, $season, $white, $black, $board): Game {
            $this->releaseFromBye($round->id, $white, $black);

            return $this->games->create(
                $round->id,
                $white,
                $black,
                $board ?? $this->nextBoard($round->id),
                null,
                $season->time_control,
            );
        });
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

        return $this->transactions->transactional(function () use ($game, $round, $data, $white, $black): Game {
            $this->releaseFromBye($round->id, $white, $black);
            $this->games->update($game->id, $data);

            return $this->requireGame($game->id);
        });
    }

    public function removePairing(int $gameId): void
    {
        $game = $this->requireGame($gameId);
        $this->requireEditableRound($this->rounds->findById($game->round_id));

        $this->games->delete($game->id);
    }

    /**
     * Mark a round complete and freeze its standings, along with every later
     * round already completed.
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
        $this->assertSeasonNotCompleted($season);

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

        $this->transactions->transactional(function () use ($round, $season, $roster, $seasonRounds, $targets, $strategy, &$computed): void {
            // Completing takes a round out of draft, which is what generating a
            // schedule requires of every round — so the two must not overlap.
            $this->lockOpenSeason($season->id);

            // In the same transaction as the scoring, because scoring can
            // refuse: Keizer prices a round against the one before it and
            // throws when that has no standings. A status committed outside
            // would survive the refusal and leave the round locked and complete
            // with nothing published behind it.
            $this->rounds->updateStatus($round->id, RoundStatus::Complete);

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
     * Close a tournament for good. Its own act rather than a flag on the last
     * round: the condition is "every round is complete", which is a fact about
     * the tournament, and a round is a poor place to ask about one.
     *
     * The rounds are read inside the transaction so a round created or reopened
     * alongside can't be missed by the check that is about to outlive it.
     */
    public function completeSeason(Season $season): void
    {
        $this->assertSeasonOpen($season->id);

        $this->transactions->transactional(function () use ($season): void {
            $this->lockOpenSeason($season->id);

            $rounds = $this->rounds->findBySeason($season->id);

            if ($rounds === []) {
                throw new ConflictException('A tournament with no rounds cannot be completed.');
            }

            foreach ($rounds as $r) {
                if ($r->status === RoundStatus::Complete) {
                    continue;
                }

                throw new ConflictException(sprintf(
                    'Round %d is still %s, so the tournament cannot be completed yet.',
                    $r->round_number,
                    $r->status->value
                ));
            }

            $update = [ 'status' => SeasonStatus::Completed->value ];

            // Until now the end date was a projection; completing is what turns
            // it into a fact, and nothing can set it afterwards. One that was
            // already entered stands — this only fills a blank.
            if ($season->end_date === null) {
                $update['end_date'] = current_time('Y-m-d');
            }

            $this->seasons->update($season->id, $update);
        });
    }

    // Why a tournament can't be closed yet, or null when it can — the admin
    // screen asks so it doesn't have to re-derive completeSeason's rule.
    public function completionBlocker(Season $season): ?string
    {
        $rounds = $this->rounds->findBySeason($season->id);

        if ($rounds === []) {
            return 'A tournament with no rounds cannot be completed.';
        }

        foreach ($rounds as $r) {
            if ($r->status !== RoundStatus::Complete) {
                return sprintf('Round %d is still %s.', $r->round_number, $r->status->value);
            }
        }

        return null;
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
        $this->assertSeasonOpen($round->season_id);

        if ($round->status !== RoundStatus::Complete) {
            throw new ConflictException('Only a completed round can be reopened.');
        }

        // Reopening makes a round incomplete, which is exactly what closing the
        // tournament checks against — so the two must not overlap.
        $this->transactions->transactional(function () use ($round): void {
            $this->lockOpenSeason($round->season_id);

            $this->rounds->updateStatus($round->id, RoundStatus::Finalised);
        });
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

    // A completed tournament is frozen; the round guards below still allow add/reopen/redate.
    public function assertSeasonOpen(int $seasonId): void
    {
        $season = $this->seasons->findById($seasonId);
        if ($season !== null) {
            $this->assertSeasonNotCompleted($season);
        }
    }

    private function assertSeasonNotCompleted(Season $season): void
    {
        if ($season->status === SeasonStatus::Completed) {
            throw new ConflictException('This tournament is completed and can no longer be changed.');
        }
    }

    // Re-check inside the transaction: the guards above run before one is open,
    // so alone they can pass against a season closed by the time the write lands.
    private function lockOpenSeason(int $seasonId): Season
    {
        $season = $this->seasons->findByIdForUpdate($seasonId);
        if ($season === null) {
            throw new NotFoundException('Season not found.');
        }

        $this->assertSeasonNotCompleted($season);

        return $season;
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
        $this->assertSeasonOpen($round->season_id);
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
        $this->assertSeasonOpen($round->season_id);
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

    // A board says the player turned up after all, which settles both columns of
    // an attendance row that says otherwise. Pairing reads status and scoring
    // reads bye_type, so leaving either behind misreports: a bye alongside a game
    // scores twice, and a stale absence drops the player from a regenerated
    // board. The whole row goes — no row is how this model says present. Every
    // bye type, not only the member's own: club duty double-counts identically.
    private function releaseFromBye(int $roundId, int ...$seasonPlayerIds): void
    {
        foreach ($seasonPlayerIds as $seasonPlayerId) {
            $row = $this->attendance->findByRoundAndSeasonPlayer($roundId, $seasonPlayerId);
            if ($row !== null && ($row->bye_type !== null || $row->status === AttendanceStatus::Absent)) {
                $this->attendance->delete($roundId, $seasonPlayerId);
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
