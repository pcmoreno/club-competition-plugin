<?php

declare(strict_types=1);

namespace SCS\Services;

use SCS\Entity\Admin;
use SCS\Entity\Enum\AdminStatus;
use SCS\Exception\ValidationException;
use SCS\Repository\AdminRepository;
use SCS\Repository\SeasonContactRepository;

/**
 * A tournament's contacts: the admins its notifications go to.
 *
 * The admin who creates a tournament is seeded as its first contact, and more
 * can be added afterwards. That seeding is only a default — the list is a plain
 * set and the creator can be taken off it again, so nothing here treats one
 * contact as special.
 *
 * **An empty list means every active admin.** That's what the club had before
 * contacts existed, so the tournaments that predate this feature carry on
 * notifying everyone with no backfill — and, more importantly, emptying the
 * list can't silently turn a tournament's notifications off. Notices that go to
 * nobody are the failure mode worth designing against here: an unread absence
 * notice for an already-published round is the whole feature.
 */
class SeasonContactService
{
    public function __construct(
        private readonly SeasonContactRepository $contacts,
        private readonly AdminRepository $admins,
    ) {
    }

    /**
     * The admin ids stored against this tournament, exactly as stored — no
     * fallback and no active filter, because this answers "what did the admin
     * choose", which is what the settings form has to render back.
     *
     * @return list<int>
     */
    public function storedAdminIds(int $seasonId): array
    {
        return $this->contacts->findAdminIdsBySeason($seasonId);
    }

    /**
     * Replace a tournament's contacts. Ids are de-duplicated, and every one of
     * them must be an active admin: a revoked account can't be chosen as a
     * recipient, and an unknown id is a client bug worth surfacing rather than
     * dropping silently.
     *
     * @param list<int> $adminIds
     * @throws ValidationException when an id isn't an active admin
     */
    public function replace(int $seasonId, array $adminIds): void
    {
        $this->contacts->replaceForSeason($seasonId, $this->validate($adminIds));
    }

    /**
     * Who actually receives this tournament's notifications: its contacts,
     * minus any that have since been revoked, falling back to every active
     * admin when that leaves nothing.
     *
     * @return list<Admin>
     */
    public function recipients(int $seasonId): array
    {
        $active = array_filter(
            $this->admins->findByIds($this->contacts->findAdminIdsBySeason($seasonId)),
            static fn (Admin $admin): bool => $admin->status === AdminStatus::Active
        );

        return $active === [] ? $this->admins->findAllActive() : array_values($active);
    }

    /** @return list<string> */
    public function recipientEmails(int $seasonId): array
    {
        return array_map(
            static fn (Admin $admin): string => $admin->email,
            $this->recipients($seasonId)
        );
    }

    /**
     * @param list<int> $adminIds
     * @return list<int>
     * @throws ValidationException
     */
    private function validate(array $adminIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $adminIds)));
        if ($ids === []) {
            return [];
        }

        $found = [];
        foreach ($this->admins->findByIds($ids) as $admin) {
            if ($admin->status === AdminStatus::Active) {
                $found[] = $admin->id;
            }
        }

        $unknown = array_diff($ids, $found);
        if ($unknown !== []) {
            throw new ValidationException([
                'contact_admin_ids' => 'Not an active admin: ' . implode(', ', $unknown) . '.',
            ]);
        }

        return $ids;
    }
}
