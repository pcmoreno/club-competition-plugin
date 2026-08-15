<?php

declare(strict_types=1);

namespace SCS\Services;

use SCS\Entity\Enum\RoundStatus;
use SCS\Entity\Enum\SeasonStatus;
use SCS\Entity\Round;
use SCS\Entity\Season;
use SCS\Exception\ConflictException;
use SCS\Exception\NotFoundException;
use SCS\Repository\RoundRepository;
use SCS\Repository\SeasonRepository;

// Where a tournament is in its life, and who may still change it: the open/closed
// guards every write consults, the season-row lock they serialize on, and closing
// itself.
final class SeasonLifecycleService
{
    public function __construct(
        private readonly SeasonRepository $seasons,
        private readonly RoundRepository $rounds,
        private readonly TransactionManager $transactions,
    ) {
    }

    // A completed tournament is frozen; the round guards elsewhere still allow add/reopen/redate.
    public function assertOpen(int $seasonId): void
    {
        $season = $this->seasons->findById($seasonId);
        if ($season !== null) {
            $this->assertNotCompleted($season);
        }
    }

    public function assertNotCompleted(Season $season): void
    {
        if ($season->status === SeasonStatus::Completed) {
            throw new ConflictException('This tournament is completed and can no longer be changed.');
        }
    }

    // Re-check inside the transaction: the guards above run before one is open,
    // so alone they can pass against a season closed by the time the write lands.
    public function lock(int $seasonId): Season
    {
        $season = $this->seasons->findByIdForUpdate($seasonId);
        if ($season === null) {
            throw new NotFoundException('Season not found.');
        }

        $this->assertNotCompleted($season);

        return $season;
    }

    /**
     * Close a tournament for good. Its own act rather than a flag on the last
     * round: the condition is "every round is complete", which is a fact about
     * the tournament, and a round is a poor place to ask about one.
     *
     * The rounds are read inside the transaction so a round created or reopened
     * alongside can't be missed by the check that is about to outlive it.
     */
    public function complete(Season $season): void
    {
        // Only a running tournament finishes. One still in preparation has
        // nothing to finish, and closing it would strand a record that can no
        // longer be edited and is past the point where it could be deleted.
        if ($season->status !== SeasonStatus::Active) {
            throw new ConflictException('Only a tournament that has started can be completed.');
        }

        $this->transactions->transactional(function () use ($season): void {
            $locked = $this->lock($season->id);

            $blocker = $this->blockerFor($this->rounds->findBySeason($season->id));
            if ($blocker !== null) {
                throw new ConflictException($blocker);
            }

            $update = [ 'status' => SeasonStatus::Completed->value ];

            // Until now the end date was a projection; completing is what turns
            // it into a fact, and nothing can set it afterwards. One that was
            // already entered stands — this only fills a blank.
            if ($locked->end_date === null) {
                $update['end_date'] = current_time('Y-m-d');
            }

            $this->seasons->update($season->id, $update);
        });
    }

    // Why a tournament can't be closed yet, or null when it can — the admin
    // screen asks so it doesn't have to re-derive the rule complete() enforces.
    public function completionBlocker(Season $season): ?string
    {
        return $this->blockerFor($this->rounds->findBySeason($season->id));
    }

    // One rule, so what the screen reports and what the write refuses can't drift.
    /** @param array<Round> $rounds */
    private function blockerFor(array $rounds): ?string
    {
        if ($rounds === []) {
            return 'A tournament with no rounds cannot be completed.';
        }

        foreach ($rounds as $round) {
            if ($round->status !== RoundStatus::Complete) {
                return sprintf('Round %d is still %s.', $round->round_number, $round->status->value);
            }
        }

        return null;
    }
}
