<?php

declare(strict_types=1);

namespace SCS\Services;

use SCS\Entity\Enum\ByeType;
use SCS\Entity\Enum\GameResult;
use SCS\Entity\SeasonPlayer;
use SCS\Entity\StandingsSnapshot;
use SCS\Exception\NotFoundException;
use SCS\Repository\AttendanceRepository;
use SCS\Repository\GameRepository;
use SCS\Repository\RoundRepository;
use SCS\Repository\SeasonPlayerRepository;
use SCS\Repository\SeasonRepository;
use SCS\Repository\StandingsSnapshotRepository;

/**
 * Builds the per-player, per-season "tournament detail" payload: one player's
 * whole run through a single season — every game (opponent, colour, own-POV
 * result), the rounds they sat out, their per-round standing for the position
 * graph, and the headline numbers from their latest snapshot.
 *
 * The viewer derives the rest from the games list (W/D/L, streaks, per-category
 * splits, best win / worst loss), so this service stays a data-composition
 * layer over the repositories rather than a stats engine.
 */
class PlayerTournamentService
{
    public function __construct(
        private readonly SeasonRepository $seasons,
        private readonly SeasonPlayerRepository $seasonPlayers,
        private readonly RoundRepository $rounds,
        private readonly GameRepository $games,
        private readonly AttendanceRepository $attendance,
        private readonly StandingsSnapshotRepository $snapshots,
        private readonly PlayerDisplayService $playerDisplay,
    ) {
    }

    /**
     * Whether the player is enrolled in any season/tournament. Gates hard
     * deletion: a player with enrolments has games and standings hanging off
     * those enrolments, so they can only be deactivated, never deleted.
     */
    public function isEnrolled(int $playerId): bool
    {
        return $this->seasonPlayers->findByPlayer($playerId) !== [];
    }

    /**
     * The seasons a player is enrolled in, newest first. enrolled_at is
     * unreliable (the import set it inconsistently), so order by the season's
     * own start date, falling back to its name (which embeds the year) when a
     * season has no start date.
     *
     * @return list<array{season_id: int, season_name: string, season_status: string, elo_rating: int|null, enrolled_at: string}>
     */
    public function enrollments(int $playerId): array
    {
        // season_id => Season, so each enrolment resolves its season in one
        // pass instead of an N+1 of findById() calls.
        $seasonsById = [];
        foreach ($this->seasons->findAll() as $season) {
            $seasonsById[$season->id] = $season;
        }

        $seasons = [];
        foreach ($this->seasonPlayers->findByPlayer($playerId) as $enrolment) {
            $season = $seasonsById[$enrolment->season_id] ?? null;
            if ($season === null) {
                continue;
            }
            $seasons[] = [$season, $enrolment];
        }

        usort($seasons, function (array $a, array $b): int {
            [$sa] = $a;
            [$sb] = $b;
            if ($sa->start_date != $sb->start_date) {
                return ($sb->start_date?->getTimestamp() ?? PHP_INT_MIN)
                    <=> ($sa->start_date?->getTimestamp() ?? PHP_INT_MIN);
            }

            return strcmp($sb->name, $sa->name);
        });

        return array_map(fn (array $pair): array => [
            'season_id'     => $pair[0]->id,
            'season_name'   => $pair[0]->name,
            'season_status' => $pair[0]->status->value,
            'elo_rating'    => $pair[1]->elo_rating,
            'enrolled_at'   => $pair[1]->enrolled_at->format('Y-m-d'),
        ], $seasons);
    }

    /**
     * @return array{
     *   season: array{id: int, name: string, status: string, categories: array, field_size: int},
     *   player: array{player_id: int, season_player_id: int, name: ?string, category: ?string, rating: int, rank: ?int, category_rank: ?int, tpr: ?int},
     *   games: list<array<string, mixed>>,
     *   positions: list<array{round_number: int, rank: int}>
     * }
     */
    public function detail(int $seasonId, int $playerId): array
    {
        $season = $this->seasons->findById($seasonId);
        if ($season === null) {
            throw new NotFoundException('Season not found.');
        }

        $seasonPlayer = $this->seasonPlayers->findBySeasonAndPlayer($seasonId, $playerId);
        if ($seasonPlayer === null) {
            throw new NotFoundException('Player is not enrolled in this season.');
        }

        // season_player_id => {player_id, name, category, elo}, so each game's
        // opponent renders without the client joining season_players ↔ players.
        $display = $this->playerDisplay->mapForSeason($seasonId);

        // round_id => {number, date}, to label games/byes by round number.
        $roundsById = [];
        foreach ($this->rounds->findBySeason($seasonId) as $round) {
            $roundsById[$round->id] = [
                'number' => $round->round_number,
                'date'   => $round->date?->format('Y-m-d'),
            ];
        }

        $rows           = [];
        $playedRoundIds = [];
        foreach ($this->games->findBySeasonPlayer($seasonPlayer->id) as $game) {
            $playedRoundIds[$game->round_id] = true;
            $isWhite       = $game->white_season_player_id === $seasonPlayer->id;
            $opponentSpId  = $isWhite ? $game->black_season_player_id : $game->white_season_player_id;
            $opponent      = $display[$opponentSpId] ?? null;
            $round         = $roundsById[$game->round_id] ?? ['number' => 0, 'date' => null];

            $rows[] = [
                'round_number' => $round['number'],
                'date'         => $round['date'],
                'color'        => $isWhite ? 'white' : 'black',
                'result'       => $this->ownResult($game->result, $isWhite),
                'is_bye'       => false,
                'opponent'     => $opponent === null ? null : [
                    'player_id' => $opponent['player_id'],
                    'name'      => $opponent['name'],
                    'category'  => $opponent['category'],
                    'rating'    => $opponent['elo'],
                ],
            ];
        }

        // Bye rows: rounds the player was assigned a pairing bye and so has no
        // game. Absences where they were paired but didn't show already appear
        // above as a game with a null result, so only unpaired byes are added.
        foreach ($this->attendance->findBySeasonPlayer($seasonPlayer->id) as $roundId => $attendance) {
            if ($attendance->bye_type !== ByeType::PairingBye || isset($playedRoundIds[$roundId])) {
                continue;
            }
            $round    = $roundsById[$roundId] ?? ['number' => 0, 'date' => null];
            $rows[]   = [
                'round_number' => $round['number'],
                'date'         => $round['date'],
                'color'        => null,
                'result'       => null,
                'is_bye'       => true,
                'opponent'     => null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['round_number'] <=> $b['round_number']);

        // Per-round standing series (position graph), plus the latest snapshot
        // for the headline rank/TPR.
        $snapshots = $this->snapshots->findBySeasonPlayer($seasonPlayer->id);
        $positions = array_map(static fn ($s): array => [
            'round_number' => $roundsById[$s->round_id]['number'] ?? 0,
            'rank'         => $s->rank,
        ], $snapshots);
        $latest = $snapshots === [] ? null : end($snapshots);

        // Current standings, used for the field size (how many players the rank
        // is out of — the viewer picks the same standings piece ♔…♙) and the
        // player's position within their own category.
        $latestRows   = $this->snapshots->findLatestForSeason($seasonId);
        $fieldSize    = count($latestRows);
        $categoryRank = $this->categoryRank($latestRows, $display, $seasonPlayer);

        $self = $display[$seasonPlayer->id] ?? null;

        return [
            'season' => [
                'id'         => $season->id,
                'name'       => $season->name,
                'status'     => $season->status->value,
                'categories' => $season->categories,
                'field_size' => $fieldSize,
            ],
            'player' => [
                'player_id'        => $seasonPlayer->player_id,
                'season_player_id' => $seasonPlayer->id,
                'name'             => $self['name'] ?? null,
                'category'         => $seasonPlayer->category,
                'rating'           => $seasonPlayer->elo_rating,
                'rank'             => $latest?->rank,
                'category_rank'    => $categoryRank,
                'tpr'              => $latest?->tpr,
            ],
            'games'     => $rows,
            'positions' => $positions,
        ];
    }

    /**
     * The player's position within their own category in the current standings:
     * their rank among same-category players, ordered by overall rank. Null for
     * an uncategorised player / undivided-pool season, or if they've dropped out
     * of the latest standings.
     *
     * @param StandingsSnapshot[]                          $latestRows ordered by rank
     * @param array<int, array{category: ?string, ...}>    $display    keyed by season_player_id
     */
    private function categoryRank(array $latestRows, array $display, SeasonPlayer $seasonPlayer): ?int
    {
        if ($seasonPlayer->category === null) {
            return null;
        }

        $position = 0;
        foreach ($latestRows as $row) {
            if (($display[$row->season_player_id]['category'] ?? null) !== $seasonPlayer->category) {
                continue;
            }
            $position++;
            if ($row->season_player_id === $seasonPlayer->id) {
                return $position;
            }
        }

        return null;
    }

    /** A game's result from the given side's point of view. */
    private function ownResult(?GameResult $result, bool $isWhite): ?string
    {
        if ($result === null) {
            return null;
        }
        if ($result === GameResult::Draw) {
            return 'draw';
        }
        $whiteWon = $result === GameResult::White;

        return $whiteWon === $isWhite ? 'win' : 'loss';
    }
}
