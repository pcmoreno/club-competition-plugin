<?php

declare(strict_types=1);

namespace SCS\Engine\Scoring;

use SCS\Engine\Settings\StandardScoringSettings;
use SCS\Entity\Enum\GameResult;
use SCS\Entity\Enum\ScoringOutcome;

// Read-only input bag for the metric calculators. Points is filled by Pass 1 before
// the points-dependent calculators (SB, Buchholz) run — that ordering lives in the orchestrator.
final class ScoringContext
{
    /**
     * @param list<int>                                                                  $playerIds
     * @param array<int,int>                                                             $ratings       season_player_id => start rating
     * @param array<int,list<array{opponent:int,outcome:ScoringOutcome,white:bool}>>     $gamesByPlayer
     * @param array<int,list<string>>                                                    $byesByPlayer  season_player_id => bye keys
     * @param array<int,float>                                                           $points        season_player_id => total points (Pass 1)
     */
    public function __construct(
        public readonly array $playerIds,
        public readonly array $ratings,
        public readonly StandardScoringSettings $settings,
        public readonly array $gamesByPlayer,
        public readonly array $byesByPlayer,
        public readonly array $points = [],
    ) {
    }

    /**
     * @param list<int>                    $playerIds
     * @param list<\SCS\Entity\Game>       $games
     * @param list<\SCS\Entity\Attendance> $attendance
     * @param array<int,int>               $ratings
     */
    public static function build(array $playerIds, array $games, array $attendance, array $ratings, StandardScoringSettings $settings): self
    {
        $gamesByPlayer = [];
        foreach ($games as $game) {
            if ($game->result === null) {
                continue;
            }

            $white = $game->white_season_player_id;
            $black = $game->black_season_player_id;

            $gamesByPlayer[$white][] = ['opponent' => $black, 'outcome' => self::outcomeFor($game->result, true), 'white' => true];
            $gamesByPlayer[$black][] = ['opponent' => $white, 'outcome' => self::outcomeFor($game->result, false), 'white' => false];
        }

        $byesByPlayer = [];
        foreach ($attendance as $row) {
            if ($row->bye_type !== null) {
                $byesByPlayer[$row->season_player_id][] = $row->bye_type->value;
            }
        }

        return new self($playerIds, $ratings, $settings, $gamesByPlayer, $byesByPlayer);
    }

    /**
     * Everyone whose points have to be computed: the roster, plus anyone who
     * appears in the games without being on it — a player removed from the
     * season, or merged away. playerIds comes from the roster while
     * gamesByPlayer comes from the games table, so the two can disagree.
     *
     * Buchholz and Sonneborn-Berger sum opponents' points, and a missing entry
     * reads as an opponent who scored zero. That understates every one of that
     * player's opponents and reorders the table — quietly, since it's a wrong
     * number rather than an error. Standings rows still come from playerIds, so
     * scoring these extra people doesn't put them in the table.
     *
     * @return list<int>
     */
    public function scoredPlayerIds(): array
    {
        $ids = [];
        foreach ($this->playerIds as $id) {
            $ids[$id] = true;
        }
        foreach (array_keys($this->gamesByPlayer) as $id) {
            $ids[$id] = true;
        }

        return array_map('intval', array_keys($ids));
    }

    /** @param array<int,float> $points */
    public function withPoints(array $points): self
    {
        return new self($this->playerIds, $this->ratings, $this->settings, $this->gamesByPlayer, $this->byesByPlayer, $points);
    }

    private static function outcomeFor(GameResult $result, bool $isWhite): ScoringOutcome
    {
        if ($result === GameResult::Draw) {
            return ScoringOutcome::Draw;
        }

        $whiteWon = $result === GameResult::White;

        return ($whiteWon === $isWhite) ? ScoringOutcome::Win : ScoringOutcome::Loss;
    }
}
