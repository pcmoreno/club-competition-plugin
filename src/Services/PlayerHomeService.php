<?php

declare(strict_types=1);

namespace SCS\Services;

use SCS\Entity\Enum\ByeType;
use SCS\Entity\Enum\RoundStatus;
use SCS\Entity\Enum\SeasonStatus;
use SCS\Entity\Round;
use SCS\Entity\Season;
use SCS\Entity\SeasonPlayer;
use SCS\Repository\AttendanceRepository;
use SCS\Repository\GameRepository;
use SCS\Repository\RoundRepository;
use SCS\Repository\SeasonPlayerRepository;
use SCS\Repository\SeasonRepository;
use SCS\Repository\StandingsSnapshotRepository;

/**
 * Builds the signed-in member's home payload: their next pairing (one per
 * tournament that has published a round), the tournaments they're in now, and
 * the ones they've finished.
 *
 * This is the only view that spans seasons — everything else is scoped by the
 * tournament switcher — because several seasons can be active at once (a league
 * season alongside a mid-season tournament). One endpoint composes it so first
 * paint costs one request; the alternative is the client fanning out per season.
 *
 * A data-composition layer over the repositories, like PlayerTournamentService:
 * the per-tournament figures are read straight off the player's latest standings
 * snapshot rather than recomputed here.
 */
class PlayerHomeService
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
     * @return array{
     *     next_pairings: list<array<string, mixed>>,
     *     current: list<array<string, mixed>>,
     *     past: list<array<string, mixed>>
     * }
     */
    public function home(int $playerId): array
    {
        $seasonsById = [];
        foreach ($this->seasons->findAll() as $season) {
            $seasonsById[$season->id] = $season;
        }

        $current      = [];
        $past         = [];
        $nextPairings = [];

        foreach ($this->seasonPlayers->findByPlayer($playerId) as $enrolment) {
            $season = $seasonsById[$enrolment->season_id] ?? null;
            if ($season === null) {
                continue;
            }

            $summary = $this->tournamentSummary($season, $enrolment);

            if ($season->status === SeasonStatus::Completed) {
                $past[] = $summary;

                continue;
            }

            $current[] = $summary;

            // Only a running tournament can have a pairing waiting to be played.
            if ($season->status === SeasonStatus::Active) {
                $pairing = $this->nextPairing($season, $enrolment);
                if ($pairing !== null) {
                    $nextPairings[] = $pairing;
                }
            }
        }

        // Soonest round first, so the evening you play next leads the page.
        usort($nextPairings, static fn (array $a, array $b): int => ($a['round']['date'] ?? '9999-12-31') <=> ($b['round']['date'] ?? '9999-12-31'));

        // Newest first within each group: what you're playing now, then what you
        // most recently finished.
        usort($current, $this->byStartDateDesc(...));
        usort($past, $this->byStartDateDesc(...));

        return [
            'next_pairings' => $nextPairings,
            'current'       => $current,
            'past'          => $past,
        ];
    }

    /**
     * One tournament card: the season, this player's place in it, and the
     * headline figures from their latest completed round. Everything but the
     * season is null before the first round completes — a player enrolled in a
     * tournament that hasn't started has no standing yet.
     *
     * @return array<string, mixed>
     */
    private function tournamentSummary(Season $season, SeasonPlayer $enrolment): array
    {
        $snapshots = $this->snapshots->findBySeasonPlayer($enrolment->id);
        $latest    = $snapshots === [] ? null : end($snapshots);

        return [
            'season' => [
                'id'           => $season->id,
                'name'         => $season->name,
                'status'       => $season->status->value,
                'time_control' => $season->time_control->value,
                'start_date'   => $season->start_date?->format('Y-m-d'),
                'end_date'     => $season->end_date?->format('Y-m-d'),
            ],
            'category'   => $enrolment->category,
            'rating'     => $enrolment->elo_rating,
            'rank'       => $latest?->rank,
            'field_size' => $latest === null ? null : count($this->snapshots->findLatestForSeason($season->id)),
            'points'     => $latest?->classical_points,
            'wins'       => $latest?->wins,
            'draws'      => $latest?->draws,
            'losses'     => $latest?->losses,
            'games'      => $latest?->games,
        ];
    }

    /**
     * The player's game in this season's upcoming round, or null when there
     * isn't one to show. "Upcoming" is the earliest round whose pairings are out
     * but which hasn't been scored yet (published or finalised) — a complete
     * round is history, and a draft one isn't public.
     *
     * Returns a row for a pairing bye too: "you're not playing this round" is as
     * much an answer as a board number.
     *
     * @return array<string, mixed>|null
     */
    private function nextPairing(Season $season, SeasonPlayer $enrolment): ?array
    {
        $round = $this->upcomingRound($season->id);
        if ($round === null) {
            return null;
        }

        $base = [
            'season' => [
                'id'   => $season->id,
                'name' => $season->name,
            ],
            'round' => [
                'id'     => $round->id,
                'number' => $round->round_number,
                'date'   => $round->date?->format('Y-m-d'),
                'status' => $round->status->value,
            ],
        ];

        foreach ($this->games->findByRound($round->id) as $game) {
            $isWhite = $game->white_season_player_id === $enrolment->id;
            if (!$isWhite && $game->black_season_player_id !== $enrolment->id) {
                continue;
            }

            $display      = $this->playerDisplay->mapForSeason($season->id);
            $opponentSpId = $isWhite ? $game->black_season_player_id : $game->white_season_player_id;
            $opponent     = $display[$opponentSpId] ?? null;

            return $base + [
                'is_bye'   => false,
                'board'    => $game->board,
                'color'    => $isWhite ? 'white' : 'black',
                'opponent' => $opponent === null ? null : [
                    'player_id' => $opponent['player_id'],
                    'name'      => $opponent['name'],
                    'category'  => $opponent['category'],
                    'rating'    => $opponent['elo'],
                ],
            ];
        }

        // Not paired: a bye is worth showing, anything else (not yet paired, or
        // marked absent) is not a pairing and belongs on no card.
        $attendance = $this->attendance->findByRoundAndSeasonPlayer($round->id, $enrolment->id);
        if ($attendance?->bye_type === ByeType::PairingBye) {
            return $base + ['is_bye' => true, 'board' => null, 'color' => null, 'opponent' => null];
        }

        return null;
    }

    /** The earliest round of this season whose pairings are out but unscored. */
    private function upcomingRound(int $seasonId): ?Round
    {
        $upcoming = array_filter(
            $this->rounds->findBySeason($seasonId),
            static fn (Round $r): bool => in_array($r->status, [RoundStatus::Published, RoundStatus::Finalised], true)
        );

        if ($upcoming === []) {
            return null;
        }

        usort($upcoming, static fn (Round $a, Round $b): int => $a->round_number <=> $b->round_number);

        return $upcoming[0];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function byStartDateDesc(array $a, array $b): int
    {
        /** @var array{start_date: ?string, name: ?string} $sa */
        $sa = $a['season'];
        /** @var array{start_date: ?string, name: ?string} $sb */
        $sb = $b['season'];

        // enrolled_at is unreliable (the import set it inconsistently), so order
        // by the season's own start date, falling back to its name — which
        // embeds the year. Same rule as PlayerTournamentService::enrollments.
        return [$sb['start_date'] ?? '', $sb['name'] ?? ''] <=> [$sa['start_date'] ?? '', $sa['name'] ?? ''];
    }
}
