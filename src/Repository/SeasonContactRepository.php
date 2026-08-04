<?php

declare(strict_types=1);

namespace SCS\Repository;

use Doctrine\DBAL\Connection;

/**
 * The season ↔ admin links behind tournament contacts. The row carries nothing
 * beyond the pair, so this deals in admin ids rather than an entity — the
 * caller resolves them through AdminRepository when it needs names or emails.
 */
class SeasonContactRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return list<int> */
    public function findAdminIdsBySeason(int $seasonId): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('admin_id')
            ->from(SCS_TABLE_PREFIX . 'season_contacts')
            ->where('season_id = :season_id')
            ->setParameter('season_id', $seasonId)
            ->orderBy('id', 'ASC')
            ->fetchFirstColumn();

        return array_values(array_map('intval', $rows));
    }

    /**
     * Replace the whole list in one go — the form always submits the full set,
     * so a diff would only add ways for the two to drift apart.
     *
     * @param list<int> $adminIds
     */
    public function replaceForSeason(int $seasonId, array $adminIds): void
    {
        $this->deleteBySeason($seasonId);

        foreach ($adminIds as $adminId) {
            $this->connection->insert(SCS_TABLE_PREFIX . 'season_contacts', [
                'season_id' => $seasonId,
                'admin_id'  => $adminId,
            ]);
        }
    }

    public function deleteBySeason(int $seasonId): void
    {
        $this->connection->delete(SCS_TABLE_PREFIX . 'season_contacts', [ 'season_id' => $seasonId ]);
    }
}
