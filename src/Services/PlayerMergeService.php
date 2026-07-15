<?php

declare(strict_types=1);

namespace SCS\Services;

use SCS\Exception\ConflictException;
use SCS\Exception\NotFoundException;
use SCS\Exception\ValidationException;
use SCS\Entity\Player;
use SCS\Repository\MemberRepository;
use SCS\Repository\PlayerRepository;
use SCS\Repository\SeasonPlayerRepository;
use SCS\Repository\SeasonRepository;

/**
 * Merges one player ("remove") into another ("keep"): everything the removed
 * player has moves to the keeper, then the removed row is gone.
 *
 * History moves for free — games, attendance, and standings all reference the
 * season_players pivot (season_player_id), never player_id — so repointing the
 * removed player's season_players rows onto the keeper carries all of that
 * along untouched.
 *
 * Beyond history, the merge fills gaps rather than discarding the removed
 * player's data:
 *  - Player fields (KNSB id, Elo + its sync stamp, birth year, gender) the
 *    keeper is missing are backfilled from the removed player. The keeper's own
 *    values always win; only its blanks are filled.
 *  - The removed player's member account, if any, moves to the keeper when the
 *    keeper has none (members is unique per player_id, so this only works into
 *    an empty slot); if the keeper already has an account, the removed one is
 *    deleted.
 *
 * Refused on a same-season collision: if both players are enrolled in one
 * season, repointing would put the keeper in it twice (the season_players
 * (season_id, player_id) unique key), and their two runs can't be fused.
 */
class PlayerMergeService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly PlayerRepository $players,
        private readonly SeasonRepository $seasons,
        private readonly SeasonPlayerRepository $seasonPlayers,
        private readonly MemberRepository $members,
    ) {
    }

    public function merge(int $keepId, int $removeId): void
    {
        if ($keepId === $removeId) {
            throw new ValidationException(['source_id' => 'Pick two different players to merge.']);
        }

        $keep = $this->players->findById($keepId);
        if ($keep === null) {
            throw new NotFoundException('Player to keep not found.');
        }
        $remove = $this->players->findById($removeId);
        if ($remove === null) {
            throw new NotFoundException('Player to remove not found.');
        }

        $overlap = $this->overlappingSeasonNames($keepId, $removeId);
        if ($overlap !== []) {
            throw new ConflictException(sprintf(
                'Both players are enrolled in the same season (%s), so they can\'t be merged.',
                implode(', ', $overlap)
            ));
        }

        $this->transactions->transactional(function () use ($keep, $remove): void {
            $this->seasonPlayers->reassignPlayer($remove->id, $keep->id);

            $this->mergeMemberAccount($keep->id, $remove->id);

            // Delete the removed player before backfilling: knsb_id is unique, so
            // handing its value to the keeper only works once the removed row
            // that held it is gone.
            $this->players->delete($remove->id);

            $fill = $this->backfillFields($keep, $remove);
            if ($fill !== []) {
                $this->players->update($keep->id, $fill);
            }
        });
    }

    /**
     * Move the removed player's member account onto the keeper when the keeper
     * has none; otherwise drop it. A member row can't outlive its player and
     * can't share a player_id (members is unique on it), so those are the only
     * two options.
     */
    private function mergeMemberAccount(int $keepId, int $removeId): void
    {
        $removeMember = $this->members->findByPlayerId($removeId);
        if ($removeMember === null) {
            return;
        }

        if ($this->members->findByPlayerId($keepId) === null) {
            $this->members->update($removeMember->id, ['player_id' => $keepId]);
        } else {
            $this->members->delete($removeMember->id);
        }
    }

    /**
     * The keeper-column fields to overwrite: each blank field on the keeper,
     * filled from the removed player where it has a value. Keeper values always
     * win — only its gaps are filled. The KNSB sync stamp rides along with the
     * Elo it belongs to.
     *
     * @return array<string, string|int>
     */
    private function backfillFields(Player $keep, Player $remove): array
    {
        $fill = [];

        if (($keep->knsb_id === null || $keep->knsb_id === '') && $remove->knsb_id !== null && $remove->knsb_id !== '') {
            $fill['knsb_id'] = $remove->knsb_id;
        }
        if ($keep->knsb_elo === null && $remove->knsb_elo !== null) {
            $fill['knsb_elo'] = $remove->knsb_elo;
            if ($keep->knsb_synced_at === null && $remove->knsb_synced_at !== null) {
                $fill['knsb_synced_at'] = $remove->knsb_synced_at->format('Y-m-d H:i:s');
            }
        }
        if ($keep->birth_year === null && $remove->birth_year !== null) {
            $fill['birth_year'] = $remove->birth_year;
        }
        if ($keep->gender === null && $remove->gender !== null) {
            $fill['gender'] = $remove->gender->value;
        }

        return $fill;
    }

    /**
     * Names of the seasons both players are enrolled in — the merge blocker.
     *
     * @return list<string>
     */
    private function overlappingSeasonNames(int $keepId, int $removeId): array
    {
        $keepSeasonIds = [];
        foreach ($this->seasonPlayers->findByPlayer($keepId) as $enrolment) {
            $keepSeasonIds[$enrolment->season_id] = true;
        }

        $names = [];
        foreach ($this->seasonPlayers->findByPlayer($removeId) as $enrolment) {
            if (!isset($keepSeasonIds[$enrolment->season_id])) {
                continue;
            }
            $season  = $this->seasons->findById($enrolment->season_id);
            $names[] = $season !== null ? $season->name : ('#' . $enrolment->season_id);
        }

        return $names;
    }
}
