<?php

declare(strict_types=1);

namespace SCS\Engine\Scoring;

use SCS\Engine\Scoring\Keizer\ValueLadder;
use SCS\Engine\Settings\KeizerScoringSettings;
use SCS\Entity\Enum\InitialValueOrder;
use SCS\Entity\Round;
use SCS\Entity\Season;
use SCS\Entity\SeasonPlayer;
use SCS\Entity\StandingsSnapshot;
use SCS\Exception\ConflictException;

/**
 * Keizer scoring: what you take off an opponent is worth what that opponent is
 * worth, and what everyone is worth follows from where they stand.
 *
 *     score = bonus × OwnV + Σ games Par(result) × OppV + Σ absences Par(reason) × OwnV
 *
 * The defining property is that the whole season is re-priced every round. A win
 * in round two against someone who has since climbed is worth more today than it
 * was then — so this is not an increment on the previous total, it is the entire
 * history recomputed against the current ladder. That costs nothing extra here,
 * because the strategy is already handed every game played so far.
 *
 * Values come from the round before (`Revaluation: Classic` — recompute values,
 * then recompute the ranking once, without iterating to a fixed point). Round
 * one has no previous ranking, so the ladder opens on the configured order.
 */
final class KeizerScoring implements ScoringStrategyInterface
{
    public function __construct(
        private readonly KeizerScoringSettings $settings,
        private readonly ValueLadder $ladder,
        private readonly PlayerScoreCalculator $playerScores,
        private readonly StandingsCalculator $standings,
    ) {
    }

    public function computeStandings(
        Season $season,
        Round $round,
        array $roster,
        array $games,
        array $attendance,
        array $previousStandings = [],
    ): array {
        // Keizer is sequential by construction: this round's values come from
        // last round's ranking, so there is no way to score round five while
        // round four is still open. A 409 the admin can read beats silently
        // laddering off the opening order and publishing wrong numbers.
        if ($round->round_number > 1 && $previousStandings === []) {
            throw new ConflictException(sprintf(
                'Round %d can only be completed after the round before it, which sets the player values it scores against.',
                $round->round_number
            ));
        }

        $values = $this->ladder->build($this->ladderOrder($roster, $previousStandings), $this->settings);

        $ratings = [];
        foreach ($roster as $player) {
            $ratings[$player->id] = $player->elo_rating;
        }

        // The standings list only those who have appeared; the ladder above
        // spans everyone enrolled. Two populations, deliberately.
        $context = ScoringContext::build($this->appeared($roster, $games, $attendance), $games, $attendance, $ratings, $this->settings);

        $snapshots = [];
        $scored    = [];
        foreach ($this->playerScores->calculate($context) as $score) {
            $keizer   = $this->keizerScore($score->seasonPlayerId, $context, $values, $round->round_number);
            $scored[] = new PlayerScore(
                $score->seasonPlayerId,
                $score->scores + ['keizer_score' => $keizer],
            );
        }

        foreach ($this->standings->rank($scored, $context) as $entry) {
            $score   = $entry['score'];
            $metrics = $score->scores;
            $hasTpr  = ($metrics['games'] ?? 0) > 0 && isset($metrics['performance_rating']);

            $snapshots[] = new StandingsSnapshot(
                id:               0,
                season_id:        $season->id,
                round_id:         $round->id,
                season_player_id: $score->seasonPlayerId,
                rank:             $entry['rank'],
                keizer_score:     (int)round((float)($metrics['keizer_score'] ?? 0.0)),
                classical_points: (float)($metrics['points'] ?? 0.0),
                wins:             (int)($metrics['wins'] ?? 0),
                draws:            (int)($metrics['draws'] ?? 0),
                losses:           (int)($metrics['losses'] ?? 0),
                games:            (int)($metrics['games'] ?? 0),
                byes:             (int)($metrics['byes'] ?? 0),
                color_balance:    (int)($metrics['color_balance'] ?? 0),
                tpr:              $hasTpr ? (int)round((float)$metrics['performance_rating']) : null,
                scores:           $metrics,
            );
        }

        return $snapshots;
    }

    /**
     * A player's score: their own value however many times the bonus says, plus
     * a share of each opponent's value, plus a share of their own for each
     * absence.
     *
     * @param array<int,float> $values
     */
    private function keizerScore(int $seasonPlayerId, ScoringContext $context, array $values, int $roundNumber): float
    {
        $own = $values[$seasonPlayerId] ?? 0.0;

        $bonus = $this->settings->aalsmeerBonus($roundNumber) + ($this->settings->addsInitialValue() ? 1 : 0);
        $total = $bonus * $own;

        foreach ($context->gamesByPlayer[$seasonPlayerId] ?? [] as $game) {
            // A missing opponent value means someone outside the roster, whose
            // rung doesn't exist; scoring them as worthless is the honest answer.
            $total += $this->settings->pointsFor($game['outcome']) * ($values[$game['opponent']] ?? 0.0);
        }

        foreach ($context->byesByPlayer[$seasonPlayerId] ?? [] as $byeKey) {
            $total += $this->settings->byePoints($byeKey) * $own;
        }

        $decimals = $this->settings->scoreDecimals();

        return $decimals === null ? $total : round($total, $decimals);
    }

    /**
     * The order the ladder's rungs are handed out in: last round's ranking, with
     * anyone who wasn't in it falling in behind by the opening order.
     *
     * @param  list<SeasonPlayer>      $roster
     * @param  list<StandingsSnapshot> $previousStandings
     * @return list<int>
     */
    private function ladderOrder(array $roster, array $previousStandings): array
    {
        $rank = [];
        foreach ($previousStandings as $snapshot) {
            $rank[$snapshot->season_player_id] = $snapshot->rank;
        }

        $ordered = $roster;
        usort($ordered, function (SeasonPlayer $a, SeasonPlayer $b) use ($rank): int {
            $rankA = $rank[$a->id] ?? PHP_INT_MAX;
            $rankB = $rank[$b->id] ?? PHP_INT_MAX;

            return $rankA <=> $rankB ?: $this->openingOrder($a, $b);
        });

        return array_map(static fn (SeasonPlayer $player) => $player->id, $ordered);
    }

    // Enrolment order breaks every tie, so the same roster always ladders the
    // same way rather than shifting with however the rows came back.
    private function openingOrder(SeasonPlayer $a, SeasonPlayer $b): int
    {
        if ($this->settings->initialOrder() === InitialValueOrder::Rating) {
            return $b->elo_rating <=> $a->elo_rating ?: $a->enrolled_at <=> $b->enrolled_at ?: $a->id <=> $b->id;
        }

        return $a->enrolled_at <=> $b->enrolled_at ?: $a->id <=> $b->id;
    }

    /**
     * Everyone who has played a game or taken a bye, in roster order.
     *
     * @param  list<SeasonPlayer>           $roster
     * @param  list<\SCS\Entity\Game>       $games
     * @param  list<\SCS\Entity\Attendance> $attendance
     * @return list<int>
     */
    private function appeared(array $roster, array $games, array $attendance): array
    {
        $seen = [];
        foreach ($games as $game) {
            if ($game->result !== null) {
                $seen[$game->white_season_player_id] = true;
                $seen[$game->black_season_player_id] = true;
            }
        }
        foreach ($attendance as $row) {
            if ($row->bye_type !== null) {
                $seen[$row->season_player_id] = true;
            }
        }

        $ids = [];
        foreach ($roster as $player) {
            if (isset($seen[$player->id])) {
                $ids[] = $player->id;
            }
        }

        return $ids;
    }
}
