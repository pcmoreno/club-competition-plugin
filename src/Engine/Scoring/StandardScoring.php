<?php

declare(strict_types=1);

namespace SCS\Engine\Scoring;

use SCS\Engine\Settings\StandardScoringSettings;
use SCS\Entity\Round;
use SCS\Entity\Season;
use SCS\Entity\StandingsSnapshot;

// Standard (classical) scoring: cumulative points + configurable tiebreaks. Pure — the service persists.
final class StandardScoring implements ScoringStrategyInterface
{
    public function __construct(
        private readonly StandardScoringSettings $settings,
        private readonly PlayerScoreCalculator $playerScores,
        private readonly StandingsCalculator $standings,
    ) {
    }

    // $previousStandings is ignored: standard scoring is cumulative over the
    // games themselves, so it never needs to know where anyone stood before.
    public function computeStandings(
        Season $season,
        Round $round,
        array $roster,
        array $games,
        array $attendance,
        array $previousStandings = [],
    ): array {
        $playerIds = [];
        $ratings   = [];
        foreach ($roster as $player) {
            $playerIds[]            = $player->id;
            $ratings[$player->id]   = $player->elo_rating;
        }

        $context = ScoringContext::build($playerIds, $games, $attendance, $ratings, $this->settings);
        $scores  = $this->playerScores->calculate($context);
        $ranked  = $this->standings->rank($scores, $context);

        $snapshots = [];
        foreach ($ranked as $entry) {
            $score  = $entry['score'];
            $values = $score->scores;
            $hasTpr = ($values['games'] ?? 0) > 0 && isset($values['performance_rating']);

            $snapshots[] = new StandingsSnapshot(
                id:               0,
                season_id:        $season->id,
                round_id:         $round->id,
                season_player_id: $score->seasonPlayerId,
                rank:             $entry['rank'],
                keizer_score:     null,
                classical_points: (float)($values['points'] ?? 0.0),
                wins:             (int)($values['wins'] ?? 0),
                draws:            (int)($values['draws'] ?? 0),
                losses:           (int)($values['losses'] ?? 0),
                games:            (int)($values['games'] ?? 0),
                byes:             (int)($values['byes'] ?? 0),
                color_balance:    (int)($values['color_balance'] ?? 0),
                tpr:              $hasTpr ? (int)round((float)$values['performance_rating']) : null,
                scores:           $values,
            );
        }

        return $snapshots;
    }
}
