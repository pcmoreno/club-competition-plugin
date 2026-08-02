<?php

declare(strict_types=1);

namespace SCS\Services;

use SCS\Entity\Admin;
use SCS\Entity\Attendance;
use SCS\Entity\Enum\AttendanceStatus;
use SCS\Entity\Enum\ByeType;
use SCS\Entity\Enum\RoundStatus;
use SCS\Entity\Enum\SeasonStatus;
use SCS\Entity\Enum\TimeControl;
use SCS\Entity\Round;
use SCS\Entity\Season;
use SCS\Entity\SeasonPlayer;
use SCS\Exception\ConflictException;
use SCS\Exception\NotFoundException;
use SCS\Repository\AdminRepository;
use SCS\Repository\AttendanceRepository;
use SCS\Repository\GameRepository;
use SCS\Repository\PlayerRepository;
use SCS\Repository\RoundRepository;
use SCS\Repository\SeasonPlayerRepository;
use SCS\Repository\SeasonRepository;

/**
 * A member telling the club they can't play a round.
 *
 * Two modes, decided by the round's status, because the app must never hold an
 * absent player who still occupies a board:
 *
 *  - SELF (round is `draft`, no pairings yet) — we record the absence
 *    ourselves: AttendanceStatus::Absent + ByeType::Personal, which is *not* a
 *    scored bye. Withdrawable while the round is still draft.
 *  - REQUEST (round is `published`, the member is already paired) — we record
 *    nothing and only email the admins, including the board they're on. The
 *    admin marks the absence and re-pairs. A member action never mutates a
 *    pairing: the opponent's board would change under them.
 *
 * Finalised and complete rounds are closed to both.
 *
 * Classical seasons only. A rapid or blitz evening is turn-up-or-don't, whereas
 * a classical season records the absence as a bye that affects scoring — so
 * there is nothing for this to do there.
 *
 * The reason is notify-only by design: it rides in the email and is never
 * stored, so there's no column for it and nothing to purge.
 */
class RoundAbsenceService
{
    /** The round is still draft: we write the absence ourselves. */
    public const MODE_SELF = 'self';

    /** Pairings are out: we only ask the admin to handle it. */
    public const MODE_REQUEST = 'request';

    public function __construct(
        private readonly SeasonRepository $seasons,
        private readonly SeasonPlayerRepository $seasonPlayers,
        private readonly RoundRepository $rounds,
        private readonly GameRepository $games,
        private readonly AttendanceRepository $attendance,
        private readonly PlayerRepository $players,
        private readonly AdminRepository $admins,
        private readonly PlayerDisplayService $playerDisplay,
        private readonly EmailNotificationService $email,
    ) {
    }

    /**
     * The rounds this player can say they'll miss: every unplayed round of every
     * running classical tournament they're in. One entry per (tournament, round)
     * — declining is per round, so two tournaments meeting the same evening are
     * two separate declarations.
     *
     * @return list<array{
     *     season: array{id: int, name: string},
     *     round: array{id: int, number: int, date: ?string, status: string},
     *     mode: string,
     *     declared: bool
     * }>
     */
    public function declinableRounds(int $playerId): array
    {
        $seasonsById = [];
        foreach ($this->seasons->findAll() as $season) {
            $seasonsById[$season->id] = $season;
        }

        $entries = [];
        foreach ($this->seasonPlayers->findByPlayer($playerId) as $enrolment) {
            $season = $seasonsById[$enrolment->season_id] ?? null;
            if ($season === null || !$this->seasonAccepts($season)) {
                continue;
            }

            foreach ($this->rounds->findBySeason($season->id) as $round) {
                $mode = $this->modeFor($round);
                if ($mode === null) {
                    continue;
                }

                $entries[] = [
                    'season'   => ['id' => $season->id, 'name' => $season->name],
                    'round'    => [
                        'id'     => $round->id,
                        'number' => $round->round_number,
                        'date'   => $round->date?->format('Y-m-d'),
                        'status' => $round->status->value,
                    ],
                    'mode'     => $mode,
                    'declared' => $this->declaredAbsence($round->id, $enrolment->id) !== null,
                ];
            }
        }

        // Soonest first. An undated round sorts last rather than dropping out —
        // it's still a round you can miss, it just reads as "Round 12" alone.
        usort($entries, static fn (array $a, array $b): int => [$a['round']['date'] ?? '9999-12-31', $a['round']['number']]
            <=> [$b['round']['date'] ?? '9999-12-31', $b['round']['number']]);

        return $entries;
    }

    /**
     * Declare that this player can't play the round. Returns the mode that was
     * applied, so the caller can tell the member whether it's recorded or with
     * the admin.
     *
     * @return array{mode: string, declared: bool}
     */
    public function declare(int $playerId, int $roundId, ?string $reason): array
    {
        [$round, $season, $enrolment] = $this->resolve($playerId, $roundId);

        $mode = $this->modeFor($round);
        if ($mode === null) {
            throw new ConflictException('This round is closed — talk to the admin.');
        }

        if ($mode === self::MODE_SELF) {
            if ($this->declaredAbsence($round->id, $enrolment->id) !== null) {
                throw new ConflictException('You have already said you can\'t play this round.');
            }
            $this->attendance->save($round->id, $enrolment->id, AttendanceStatus::Absent, ByeType::Personal);
        }

        $this->notify($mode, 'declared', $playerId, $round, $season, $enrolment, $reason);

        return ['mode' => $mode, 'declared' => $mode === self::MODE_SELF];
    }

    /**
     * Withdraw a declared absence ("I can play after all"). Only the row this
     * service wrote is removed — an admin re-classification (a club-duty bye,
     * say) is their decision to reverse, not the member's.
     */
    public function withdraw(int $playerId, int $roundId): void
    {
        [$round, $season, $enrolment] = $this->resolve($playerId, $roundId);

        if ($this->modeFor($round) !== self::MODE_SELF) {
            throw new ConflictException('Pairings are already out for this round — talk to the admin.');
        }

        if ($this->declaredAbsence($round->id, $enrolment->id) === null) {
            throw new NotFoundException('You have nothing to withdraw for this round.');
        }

        $this->attendance->delete($round->id, $enrolment->id);

        $this->notify(self::MODE_SELF, 'withdrawn', $playerId, $round, $season, $enrolment, null);
    }

    /**
     * The round, its season and this player's enrolment — or the reason none of
     * it applies.
     *
     * @return array{Round, Season, SeasonPlayer}
     */
    private function resolve(int $playerId, int $roundId): array
    {
        $round = $this->rounds->findById($roundId);
        if ($round === null) {
            throw new NotFoundException('Round not found.');
        }

        $season = $this->seasons->findById($round->season_id);
        if ($season === null) {
            throw new NotFoundException('Season not found for round.');
        }
        if (!$this->seasonAccepts($season)) {
            throw new ConflictException('This tournament doesn\'t take absence notices.');
        }

        $enrolment = $this->seasonPlayers->findBySeasonAndPlayer($season->id, $playerId);
        if ($enrolment === null) {
            throw new NotFoundException('You\'re not enrolled in this tournament.');
        }

        return [$round, $season, $enrolment];
    }

    /** Running, and classical — the only place a personal bye means anything. */
    private function seasonAccepts(Season $season): bool
    {
        return $season->status === SeasonStatus::Active
            && $season->time_control === TimeControl::Classical;
    }

    /** Which mode this round's status allows, or null when it's closed. */
    private function modeFor(Round $round): ?string
    {
        return match ($round->status) {
            RoundStatus::Draft     => self::MODE_SELF,
            RoundStatus::Published => self::MODE_REQUEST,
            default                => null,
        };
    }

    /**
     * This player's own declaration for the round, if any. Deliberately narrow:
     * only Absent + Personal is ours to report as declared or to withdraw.
     */
    private function declaredAbsence(int $roundId, int $seasonPlayerId): ?Attendance
    {
        $attendance = $this->attendance->findByRoundAndSeasonPlayer($roundId, $seasonPlayerId);

        if ($attendance === null
            || $attendance->status !== AttendanceStatus::Absent
            || $attendance->bye_type !== ByeType::Personal) {
            return null;
        }

        return $attendance;
    }

    /**
     * Tell the admins. For a request against a published round this carries the
     * board the member is currently on, which is the whole point — that's what
     * has to be re-paired.
     */
    private function notify(
        string $mode,
        string $action,
        int $playerId,
        Round $round,
        Season $season,
        SeasonPlayer $enrolment,
        ?string $reason,
    ): void {
        $player = $this->players->findById($playerId);
        if ($player === null) {
            throw new NotFoundException('Player not found.');
        }

        $this->email->sendAbsenceNotice(
            $this->adminEmails(),
            $player->name,
            $season->name,
            $round->round_number,
            $round->date?->format('Y-m-d'),
            $mode,
            $action,
            $reason,
            $mode === self::MODE_REQUEST ? $this->pairingLine($season->id, $round->id, $enrolment->id) : null,
        );
    }

    /** @return list<string> */
    private function adminEmails(): array
    {
        return array_map(
            static fn (Admin $admin): string => $admin->email,
            $this->admins->findAllActive()
        );
    }

    /** e.g. "board 12 as Black against Jan Burggraaf", or null when unpaired. */
    private function pairingLine(int $seasonId, int $roundId, int $seasonPlayerId): ?string
    {
        foreach ($this->games->findByRound($roundId) as $game) {
            $isWhite = $game->white_season_player_id === $seasonPlayerId;
            if (!$isWhite && $game->black_season_player_id !== $seasonPlayerId) {
                continue;
            }

            $display  = $this->playerDisplay->mapForSeason($seasonId);
            $opponent = $display[$isWhite ? $game->black_season_player_id : $game->white_season_player_id] ?? null;

            return sprintf(
                'board %s as %s against %s',
                $game->board ?? '?',
                $isWhite ? 'White' : 'Black',
                $opponent['name'] ?? 'an unknown opponent',
            );
        }

        return null;
    }
}
