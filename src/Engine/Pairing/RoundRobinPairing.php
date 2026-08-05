<?php

declare(strict_types=1);

namespace SCS\Engine\Pairing;

use SCS\Engine\Settings\RoundRobinSettings;
use SCS\Entity\Enum\ByeType;
use SCS\Entity\Enum\GroupingMode;
use SCS\Entity\Enum\SeedingMethod;
use SCS\Entity\Season;
use SCS\Entity\SeasonPlayer;
use SCS\Exception\ConflictException;

/**
 * Everyone plays everyone, laid out as a FIDE Berger table.
 *
 * The whole fixture is score-independent, so it is produced in one call and the
 * service persists it as a run of draft rounds. Two consequences worth knowing:
 * the roster is locked from that moment (a late enrolment shifts every pairing
 * number and invalidates rounds already played), and a two-player tournament is
 * simply a match — 20 legs of two players is a 20-game match with alternating
 * colours, out of this same code.
 */
final class RoundRobinPairing implements FullSchedulePairing
{
    // rounds.round_number is TINYINT UNSIGNED, so no schedule can run past this.
    public const MAX_ROUNDS = 255;

    public function __construct(private readonly RoundRobinSettings $settings)
    {
    }

    /**
     * @param  list<SeasonPlayer> $roster
     * @return list<PairingResult> one per round, in order
     */
    public function pairSchedule(Season $season, array $roster): array
    {
        $groups  = $this->groups($roster);
        $biggest = max(array_map('count', $groups));

        // Checked before anything is built: the settings cap legs on their own,
        // but what is actually bounded is legs × field size, and only here are
        // both known.
        $roundCount = $this->settings->legs() * $this->roundsPerLeg($biggest);
        if ($roundCount > self::MAX_ROUNDS) {
            throw new ConflictException(sprintf(
                '%d legs of %d players is %d rounds, and a tournament can run at most %d.',
                $this->settings->legs(),
                $biggest,
                $roundCount,
                self::MAX_ROUNDS
            ));
        }

        $schedules = array_map($this->groupSchedule(...), $groups);

        // Groups can be different sizes, so a smaller one runs out of rounds
        // before the rest. Its players simply have no game that round — not a
        // bye, which is a scored outcome; they have finished their schedule.
        $rounds = [];
        for ($i = 0; $i < $roundCount; $i++) {
            $pairings = [];
            $byes     = [];
            $board    = 1;

            foreach ($schedules as $schedule) {
                if (!isset($schedule[$i])) {
                    continue;
                }
                foreach ($schedule[$i]['pairs'] as [$white, $black]) {
                    $pairings[] = ['white' => $white, 'black' => $black, 'board' => $board++];
                }
                foreach ($schedule[$i]['byes'] as $seasonPlayerId) {
                    $byes[] = [
                        'season_player_id' => $seasonPlayerId,
                        'bye_type'         => ByeType::PairingBye->value,
                    ];
                }
            }

            $rounds[] = new PairingResult($pairings, $byes);
        }

        return $rounds;
    }

    // An even field plays N-1 rounds, an odd one N — the extra round is where
    // each player takes their turn sitting out.
    private function roundsPerLeg(int $size): int
    {
        return $size % 2 === 0 ? $size - 1 : $size;
    }

    /**
     * Split the roster into the fields that each play their own round-robin, and
     * seed each one.
     *
     * @param  list<SeasonPlayer>                 $roster
     * @return non-empty-list<list<SeasonPlayer>>
     */
    private function groups(array $roster): array
    {
        if (count($roster) < 2) {
            throw new ConflictException('A round-robin needs at least two enrolled players.');
        }

        if ($this->settings->grouping() !== GroupingMode::Categories) {
            return [$this->seed($roster)];
        }

        // Uncategorised players are a group of their own rather than an error:
        // categories are optional per season, and a half-categorised roster is
        // the admin's to sort out — but a group of one has no tournament to play.
        $byCategory = [];
        foreach ($roster as $player) {
            $byCategory[$player->category ?? ''][] = $player;
        }
        ksort($byCategory);

        $groups = [];
        foreach ($byCategory as $category => $players) {
            if (count($players) < 2) {
                throw new ConflictException(sprintf(
                    '%s has only one player, so it has no round-robin to play. Move them to another category first.',
                    $category === '' ? 'The players without a category' : sprintf('Category “%s”', $category)
                ));
            }
            $groups[] = $this->seed($players);
        }

        return $groups;
    }

    /**
     * Assign pairing numbers. The Berger table is fully determined by this
     * order, so it is the schedule's only free input.
     *
     * @param  list<SeasonPlayer> $players
     * @return list<SeasonPlayer>
     */
    private function seed(array $players): array
    {
        $seeded = $players;

        switch ($this->settings->seeding()) {
            case SeedingMethod::Rating:
                // Enrolment order breaks a rating tie, so the same roster always
                // seeds the same way — findBySeason already returns that order.
                usort($seeded, static fn (SeasonPlayer $a, SeasonPlayer $b) => $b->elo_rating <=> $a->elo_rating ?: $a->id <=> $b->id);

                break;
            case SeedingMethod::Lot:
                shuffle($seeded);

                break;
            case SeedingMethod::Enrolment:
                usort($seeded, static fn (SeasonPlayer $a, SeasonPlayer $b) => $a->enrolled_at <=> $b->enrolled_at ?: $a->id <=> $b->id);

                break;
        }

        return $seeded;
    }

    /**
     * One group's rounds, with pairing numbers already resolved to season-player
     * ids.
     *
     * @param  list<SeasonPlayer> $players seeded
     * @return list<array{pairs: list<array{0:int,1:int}>, byes: list<int>}>
     */
    private function groupSchedule(array $players): array
    {
        $size = count($players);
        // An odd field plays as if one more player were present; whoever draws
        // that number sits out, which works out at exactly once each.
        $slots = $size % 2 === 0 ? $size : $size + 1;
        $table = $this->bergerTable($slots);

        $schedule = [];
        for ($leg = 1; $leg <= $this->settings->legs(); $leg++) {
            $flip = $this->settings->alternateColoursPerLeg() && $leg % 2 === 0;

            foreach ($table as $round) {
                $pairs = [];
                $byes  = [];

                foreach ($round as [$white, $black]) {
                    // The absent slot in an odd field: its opponent has the round off.
                    if ($white > $size) {
                        $byes[] = $players[$black - 1]->id;

                        continue;
                    }
                    if ($black > $size) {
                        $byes[] = $players[$white - 1]->id;

                        continue;
                    }

                    $pairs[] = $flip
                        ? [$players[$black - 1]->id, $players[$white - 1]->id]
                        : [$players[$white - 1]->id, $players[$black - 1]->id];
                }

                $schedule[] = ['pairs' => $pairs, 'byes' => $byes];
            }
        }

        return $schedule;
    }

    /**
     * The FIDE Berger table for an even number of slots, as [white, black]
     * pairing numbers.
     *
     * Slot `n` stays put while 1..n-1 rotate around it. Its opponent in round r
     * is `k`, and every other pair is (k+d, k-d) counting around that rotation —
     * the higher offset taking white, and n itself alternating by round. That
     * reproduces the published tables exactly, which matters because organisers
     * check schedules against them.
     *
     * @return list<list<array{0:int,1:int}>>
     */
    private function bergerTable(int $slots): array
    {
        $rotating = $slots - 1;
        $rounds   = [];

        for ($r = 1; $r <= $rotating; $r++) {
            $k = $r % 2 === 1
                ? intdiv($r + 1, 2)
                : intdiv($r, 2) + intdiv($rotating + 1, 2);

            $pairs = [$r % 2 === 0 ? [$slots, $k] : [$k, $slots]];

            for ($d = 1; $d <= intdiv($rotating - 1, 2); $d++) {
                $pairs[] = [
                    $this->wrap($k + $d, $rotating),
                    $this->wrap($k - $d, $rotating),
                ];
            }

            // Board 1 goes to the pair containing the highest seed, which is what
            // an organiser expects to read down the sheet. The table's own order
            // is a presentation of the rotation, not a ranking.
            usort($pairs, static fn (array $a, array $b) => min($a) <=> min($b));

            $rounds[] = $pairs;
        }

        return $rounds;
    }

    // Fold a rotation offset back into 1..$rotating.
    private function wrap(int $number, int $rotating): int
    {
        return ((($number - 1) % $rotating) + $rotating) % $rotating + 1;
    }
}
