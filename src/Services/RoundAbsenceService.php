<?php

declare(strict_types=1);

namespace SCS\Services;

use SCS\Entity\Attendance;
use SCS\Entity\Enum\AttendanceStatus;
use SCS\Entity\Enum\ByeType;
use SCS\Entity\Enum\RoundStatus;
use SCS\Entity\Enum\SeasonStatus;
use SCS\Entity\Enum\TimeControl;
use SCS\Entity\Game;
use SCS\Entity\Round;
use SCS\Entity\Season;
use SCS\Entity\SeasonPlayer;
use SCS\Exception\ConflictException;
use SCS\Exception\NotFoundException;
use SCS\Exception\TooManyRequestsException;
use SCS\Repository\AttendanceRepository;
use SCS\Repository\GameRepository;
use SCS\Repository\PlayerRepository;
use SCS\Repository\RoundRepository;
use SCS\Repository\SeasonPlayerRepository;
use SCS\Repository\SeasonRepository;

/**
 * A member telling the club they can't play a round.
 *
 * Two modes, decided by whether the member is actually paired, because the app
 * must never hold an absent player who still occupies a board:
 *
 *  - SELF (not on a board) — we record the absence ourselves:
 *    AttendanceStatus::Absent + ByeType::Personal. Under standard scoring that
 *    is worth nothing; under Keizer it is worth Par(personal) of the player's
 *    own value, 0.3333 by default, so the member's own declaration is scored
 *    competition data. That is Sevilla's behaviour and the coefficient is a
 *    season setting — a club that doesn't want it sets personal to zero before
 *    the first round completes, after which scoring locks.
 *    Withdrawable while they stay unpaired.
 *  - REQUEST (already paired) — we record nothing and only email the admins,
 *    including the board they're on. The admin marks the absence and re-pairs.
 *    A member action never mutates a pairing: the opponent's board would change
 *    under them.
 *
 * Pairing presence decides this, not round status: `draft` is exactly the phase
 * in which the admin builds the board (RoundService::requireEditableRound lets
 * pairings be edited until the round is finalised), so a draft round routinely
 * has pairings on it. Keying off the status would mark a paired player absent
 * and tell the admin there was nothing to do.
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
    /** Not on a board: we write the absence ourselves. */
    public const MODE_SELF = 'self';

    /** Already paired: we only ask the admin to handle it. */
    public const MODE_REQUEST = 'request';

    /** Nothing recorded — declaring is offered. */
    public const STATE_OPEN = 'open';

    /** Our own Absent + Personal row, and still ours to withdraw. */
    public const STATE_DECLARED = 'declared';

    /** The admin has been told; nothing more for the member to do. */
    public const STATE_NOTIFIED = 'notified';

    /** Recorded, but not the member's to change — talk to the admin. */
    public const STATE_LOCKED = 'locked';

    // Every declaration mails the tournament's contacts, so the endpoint is a
    // mail trigger and is throttled like the other two (login, password reset).
    private const NOTICE_MAX_PER_WINDOW = 10;
    private const NOTICE_DECAY_SECONDS  = 3600;

    // A request against a paired round writes nothing, so nothing else can stop
    // a repeat submission from mailing the admins again. This marker is the
    // idempotency record: one notice per member per round, for long enough to
    // outlive the round.
    private const NOTICE_ONCE_SECONDS = 2592000;

    public function __construct(
        private readonly SeasonRepository $seasons,
        private readonly SeasonPlayerRepository $seasonPlayers,
        private readonly RoundRepository $rounds,
        private readonly GameRepository $games,
        private readonly AttendanceRepository $attendance,
        private readonly PlayerRepository $players,
        private readonly SeasonContactService $seasonContacts,
        private readonly PlayerDisplayService $playerDisplay,
        private readonly EmailNotificationService $email,
        private readonly RateLimiterService $rateLimiter,
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
     *     state: string
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

            // One query each rather than one per round: this feeds /me/home,
            // the view every member lands on after login.
            $attendanceByRound = $this->attendance->findBySeasonPlayer($enrolment->id);
            $pairedRounds      = $this->pairedRoundIds($enrolment->id);

            foreach ($this->rounds->findBySeason($season->id) as $round) {
                $mode = $this->modeFor($round, isset($pairedRounds[$round->id]));
                if ($mode === null) {
                    continue;
                }

                $entries[] = [
                    'season' => ['id' => $season->id, 'name' => $season->name],
                    'round'  => [
                        'id'     => $round->id,
                        'number' => $round->round_number,
                        'date'   => $round->date?->format('Y-m-d'),
                        'status' => $round->status->value,
                    ],
                    'mode'   => $mode,
                    'state'  => $this->stateFor($mode, $attendanceByRound[$round->id] ?? null, $round->id, $enrolment->id),
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
     * the admin, and whether the mail actually went out.
     *
     * @return array{mode: string, declared: bool, notified: bool}
     */
    public function declare(int $playerId, int $roundId, ?string $reason): array
    {
        [$round, $season, $enrolment] = $this->resolve($playerId, $roundId);

        $game = $this->pairedGame($round->id, $enrolment->id);
        $mode = $this->modeFor($round, $game !== null);
        if ($mode === null) {
            throw new ConflictException('This round is closed — talk to the admin.');
        }

        $this->requireNoticeAllowance($playerId);

        // Any bye row is competition data under Keizer — the member's own
        // included — so overwriting one here would let a member rewrite the
        // standings. A bare status row (no bye_type) carries no such decision
        // and stays overwritable, so the normal path is untouched.
        $existing = $this->attendance->findByRoundAndSeasonPlayer($round->id, $enrolment->id);
        if ($existing !== null && $existing->bye_type !== null) {
            throw new ConflictException($this->isOwnDeclaration($existing)
                ? 'You have already said you can\'t play this round.'
                : 'The admin has already recorded your attendance for this round — talk to them.');
        }

        if ($mode === self::MODE_REQUEST && $this->alreadyNotified($round->id, $enrolment->id)) {
            throw new ConflictException('You have already told the admin about this round.');
        }

        if ($mode === self::MODE_SELF) {
            $this->attendance->save($round->id, $enrolment->id, AttendanceStatus::Absent, ByeType::Personal);
        }

        $notified = $this->notify($mode, 'declared', $playerId, $round, $season, $enrolment, $reason, $game);

        // Only once the mail actually went out: a request writes nothing, so
        // marking a failed send as "already told them" would leave the member
        // with no way to tell them at all.
        if ($mode === self::MODE_REQUEST && $notified) {
            $this->rateLimiter->hit($this->noticeOnceKey($round->id, $enrolment->id), self::NOTICE_ONCE_SECONDS);
        }

        return ['mode' => $mode, 'declared' => $mode === self::MODE_SELF, 'notified' => $notified];
    }

    /**
     * Withdraw a declared absence ("I can play after all"). Only the row this
     * service wrote is removed — an admin re-classification (a club-duty bye,
     * say) is their decision to reverse, not the member's. There is nothing to
     * withdraw once they're on a board: pairing a player drops whatever bye they
     * held, so the refusal below reports a state, not a rule.
     */
    public function withdraw(int $playerId, int $roundId): void
    {
        [$round, $season, $enrolment] = $this->resolve($playerId, $roundId);

        $mode = $this->modeFor($round, $this->pairedGame($round->id, $enrolment->id) !== null);
        if ($mode === null) {
            throw new ConflictException('This round is closed — talk to the admin.');
        }
        if ($mode !== self::MODE_SELF) {
            throw new ConflictException('You\'re already paired for this round — talk to the admin.');
        }

        $existing = $this->attendance->findByRoundAndSeasonPlayer($round->id, $enrolment->id);
        if ($existing === null || !$this->isOwnDeclaration($existing)) {
            throw new NotFoundException('You have nothing to withdraw for this round.');
        }

        $this->requireNoticeAllowance($playerId);

        $this->attendance->delete($round->id, $enrolment->id);

        $this->notify(self::MODE_SELF, 'withdrawn', $playerId, $round, $season, $enrolment, null, null);
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

    /**
     * Which mode applies, or null when the round is closed to both. Being on a
     * board is what decides it — see the class docblock.
     */
    private function modeFor(Round $round, bool $paired): ?string
    {
        if ($round->status !== RoundStatus::Draft && $round->status !== RoundStatus::Published) {
            return null;
        }

        return $paired ? self::MODE_REQUEST : self::MODE_SELF;
    }

    /**
     * What the member may do with this round, so the card copy and the withdraw
     * affordance can't promise something the write path will refuse.
     */
    private function stateFor(string $mode, ?Attendance $attendance, int $roundId, int $seasonPlayerId): string
    {
        if ($attendance !== null && $attendance->bye_type !== null) {
            return $this->isOwnDeclaration($attendance) && $mode === self::MODE_SELF
                ? self::STATE_DECLARED
                : self::STATE_LOCKED;
        }

        if ($mode === self::MODE_REQUEST && $this->alreadyNotified($roundId, $seasonPlayerId)) {
            return self::STATE_NOTIFIED;
        }

        return self::STATE_OPEN;
    }

    /**
     * Whether this row is the one this service wrote. Deliberately narrow: only
     * Absent + Personal is ours to report as declared or to withdraw.
     */
    private function isOwnDeclaration(Attendance $attendance): bool
    {
        return $attendance->status === AttendanceStatus::Absent
            && $attendance->bye_type === ByeType::Personal;
    }

    /** @throws TooManyRequestsException when this member is mailing too fast. */
    private function requireNoticeAllowance(int $playerId): void
    {
        if ($this->rateLimiter->tooManyAttempts($this->noticeKey($playerId), self::NOTICE_MAX_PER_WINDOW)) {
            throw new TooManyRequestsException('You\'ve sent a lot of absence notices — please try again later.');
        }
    }

    private function alreadyNotified(int $roundId, int $seasonPlayerId): bool
    {
        return $this->rateLimiter->tooManyAttempts($this->noticeOnceKey($roundId, $seasonPlayerId), 1);
    }

    private function noticeKey(int $playerId): string
    {
        return 'absence_player_' . $playerId;
    }

    private function noticeOnceKey(int $roundId, int $seasonPlayerId): string
    {
        return 'absence_notice_' . $roundId . '_' . $seasonPlayerId;
    }

    /** The member's game in this round, or null when they aren't paired. */
    private function pairedGame(int $roundId, int $seasonPlayerId): ?Game
    {
        foreach ($this->games->findByRound($roundId) as $game) {
            if ($game->white_season_player_id === $seasonPlayerId
                || $game->black_season_player_id === $seasonPlayerId) {
                return $game;
            }
        }

        return null;
    }

    /**
     * Every round of this season the player is already paired in.
     *
     * @return array<int, true> keyed by round_id
     */
    private function pairedRoundIds(int $seasonPlayerId): array
    {
        $rounds = [];
        foreach ($this->games->findBySeasonPlayer($seasonPlayerId) as $game) {
            $rounds[$game->round_id] = true;
        }

        return $rounds;
    }

    /**
     * Tell the admins, and say whether the mail went out. When the member is on
     * a board this carries it, which is the whole point — that's what has to be
     * re-paired.
     */
    private function notify(
        string $mode,
        string $action,
        int $playerId,
        Round $round,
        Season $season,
        SeasonPlayer $enrolment,
        ?string $reason,
        ?Game $game,
    ): bool {
        $player = $this->players->findById($playerId);
        if ($player === null) {
            throw new NotFoundException('Player not found.');
        }

        // The tournament's contacts, not every admin — see SeasonContactService,
        // which still falls back to all of them when a tournament has none.
        $recipients = $this->seasonContacts->recipientEmails($season->id);

        $sent = $this->email->sendAbsenceNotice(
            $recipients,
            $player->name,
            $season->name,
            $round->round_number,
            $round->date?->format('Y-m-d'),
            $mode,
            $action,
            $reason,
            $game === null ? null : $this->pairingLine($game, $season->id, $enrolment->id),
        );

        $this->rateLimiter->hit($this->noticeKey($playerId), self::NOTICE_DECAY_SECONDS);

        // For a request nothing is written, so this line is the only trace the
        // event happened at all. Ids only — no names, no reason.
        error_log(sprintf(
            '[SCS] absence %s player=%d round=%d mode=%s recipients=%d sent=%s',
            $action,
            $playerId,
            $round->id,
            $mode,
            count($recipients),
            $sent ? 'yes' : 'no',
        ));

        return $sent;
    }

    /** e.g. "board 12 as Black against Jan Burggraaf". */
    private function pairingLine(Game $game, int $seasonId, int $seasonPlayerId): string
    {
        $isWhite  = $game->white_season_player_id === $seasonPlayerId;
        $display  = $this->playerDisplay->mapForSeason($seasonId);
        $opponent = $display[$isWhite ? $game->black_season_player_id : $game->white_season_player_id] ?? null;

        return sprintf(
            'board %s as %s against %s',
            $game->board ?? '?',
            $isWhite ? 'White' : 'Black',
            $opponent['name'] ?? 'an unknown opponent',
        );
    }
}
