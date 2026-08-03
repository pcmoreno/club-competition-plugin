<?php

declare(strict_types=1);

namespace SCS\Services;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use SCS\Entity\Player;
use SCS\Repository\PlayerRepository;

/**
 * Applies the stored KNSB rating list to players: one at a time (the roster's
 * per-player Sync action) or across the whole roster ("Sync KNSB ratings" in the
 * roster's Actions menu). Fetching the list is a separate step — see
 * KnsbRatingListFetcher and KnsbRatingStore.
 *
 * KNSB is the source of truth, so a sync overwrites name (normalised to the
 * club's "given-name first" convention), birth year and rating. Players are
 * matched on knsb_id alone; a player without one can't be synced.
 *
 * Every synced player is stamped, changed or not, because knsb_synced_at means
 * "checked against the list on this date" — that's what the roster's staleness
 * highlight reads.
 */
class KnsbRatingSyncService
{
    public const OUTCOME_UPDATED = 'updated';

    // Applied, but every field already matched — distinct from not applied.
    public const OUTCOME_UNCHANGED = 'unchanged';

    public const OUTCOME_NO_KNSB_ID = 'no_knsb_id';

    // The knsb_id isn't in the list currently stored on the server.
    public const OUTCOME_NOT_LISTED = 'not_listed';

    // KNSB's name for this player is already taken by another player row.
    public const OUTCOME_NAME_CONFLICT = 'name_conflict';

    public function __construct(
        private readonly PlayerRepository $players,
        private readonly KnsbRatingStore $store,
        private readonly KnsbNameNormalizer $normalizer,
    ) {
    }

    /** Has a list been fetched at all? Both callers refuse to run without one. */
    public function listAvailable(): bool
    {
        return $this->store->read() !== null;
    }

    /**
     * Sync one player against the stored list. Never throws for a per-player
     * problem — the outcome says what happened, so the bulk run can record it
     * and carry on.
     *
     * @return array{outcome: string, player: ?Player, name: string, changes: array<string, array{before: int|string|null, after: int|string|null}>}
     */
    public function sync(Player $player): array
    {
        if ($player->knsb_id === null || $player->knsb_id === '') {
            return $this->outcome(self::OUTCOME_NO_KNSB_ID, $player->name);
        }

        $row = $this->store->findRating($player->knsb_id);
        if ($row === null) {
            return $this->outcome(self::OUTCOME_NOT_LISTED, $player->name);
        }

        // An unparseable KNSB name would blank the player's, so keep theirs.
        $name = $this->normalizer->normalize((string)$row['name']);
        if ($name === '') {
            $name = $player->name;
        }

        $birthYear = $row['birth_year'] ?? null;
        $elo       = (int)$row['rating'];

        try {
            $this->players->applyKnsbData($player->id, $name, $birthYear, $elo, current_time('mysql'));
        } catch (UniqueConstraintViolationException) {
            return $this->outcome(self::OUTCOME_NAME_CONFLICT, $name);
        }

        $changes = [];
        if ($name !== $player->name) {
            $changes['name'] = ['before' => $player->name, 'after' => $name];
        }
        if ($birthYear !== $player->birth_year) {
            $changes['birth_year'] = ['before' => $player->birth_year, 'after' => $birthYear];
        }
        if ($elo !== $player->knsb_elo) {
            $changes['knsb_elo'] = ['before' => $player->knsb_elo, 'after' => $elo];
        }

        return [
            'outcome' => $changes === [] ? self::OUTCOME_UNCHANGED : self::OUTCOME_UPDATED,
            'player'  => $this->players->findById($player->id),
            'name'    => $name,
            'changes' => $changes,
        ];
    }

    /**
     * Sync every player who can be synced — anyone with a KNSB id, active or
     * not. Each player stands alone: a name collision or an id missing from the
     * list is recorded and the run continues, so one bad row can't cost the
     * admin the whole batch. Deliberately not wrapped in a transaction for the
     * same reason.
     *
     * @return array{
     *     total: int,
     *     skipped: int,
     *     updated: int,
     *     unchanged: int,
     *     failed: int,
     *     changes: list<array{id: int, name: string, fields: array<string, array{before: int|string|null, after: int|string|null}>}>,
     *     failures: list<array{id: int, name: string, reason: string}>
     * }
     */
    public function syncAll(): array
    {
        $report = [
            'total'     => 0,
            'skipped'   => 0,
            'updated'   => 0,
            'unchanged' => 0,
            'failed'    => 0,
            'changes'   => [],
            'failures'  => [],
        ];

        foreach ($this->players->findAll() as $player) {
            $report['total']++;

            $result = $this->sync($player);

            switch ($result['outcome']) {
                case self::OUTCOME_UPDATED:
                    $report['updated']++;
                    $report['changes'][] = [
                        'id'     => $player->id,
                        'name'   => $result['name'],
                        'fields' => $result['changes'],
                    ];

                    break;

                case self::OUTCOME_UNCHANGED:
                    $report['unchanged']++;

                    break;

                case self::OUTCOME_NO_KNSB_ID:
                    $report['skipped']++;

                    break;

                default:
                    $report['failed']++;
                    $report['failures'][] = [
                        'id'     => $player->id,
                        'name'   => $player->name,
                        'reason' => $result['outcome'],
                    ];
            }
        }

        return $report;
    }

    /**
     * @return array{outcome: string, player: null, name: string, changes: array<string, array{before: int|string|null, after: int|string|null}>}
     */
    private function outcome(string $outcome, string $name): array
    {
        return ['outcome' => $outcome, 'player' => null, 'name' => $name, 'changes' => []];
    }
}
