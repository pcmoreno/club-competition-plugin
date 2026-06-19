<?php

declare(strict_types=1);

namespace SCS\Repository;

use Doctrine\DBAL\Connection;
use SCS\Entity\Enum\RoundStatus;
use SCS\Entity\Round;

class RoundRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findBySeason(int $season_id): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('wp_scs_rounds')
            ->where('season_id = :season_id')
            ->setParameter('season_id', $season_id)
            ->orderBy('round_number', 'ASC')
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findById(int $id): ?Round
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('wp_scs_rounds')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    public function findLatestPublishedBySeason(int $season_id): ?Round
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('wp_scs_rounds')
            ->where('season_id = :season_id')
            ->andWhere('status != :status')
            ->setParameter('season_id', $season_id)
            ->setParameter('status', RoundStatus::Draft->value)
            ->orderBy('round_number', 'DESC')
            ->setMaxResults(1)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Create a round with an explicit number and status. Used by the season
     * import, which seeds historical rounds verbatim (already complete) rather
     * than appending the next draft round — see createNextForSeason for that.
     */
    public function create(int $season_id, int $round_number, ?string $date, RoundStatus $status): Round
    {
        $this->connection->insert('wp_scs_rounds', [
            'season_id'    => $season_id,
            'round_number' => $round_number,
            'date'         => $date,
            'status'       => $status->value,
        ]);

        return $this->findById((int)$this->connection->lastInsertId());
    }

    public function deleteBySeason(int $season_id): void
    {
        $this->connection->delete('wp_scs_rounds', [ 'season_id' => $season_id ]);
    }

    public function createNextForSeason(int $season_id, ?string $date): Round
    {
        return $this->connection->transactional(function () use ($season_id, $date): Round {
            // forUpdate() emits SELECT ... FOR UPDATE to lock the gap and serialize
            // concurrent inserts, so two requests can't claim the same round_number.
            // This relies on MySQL/InnoDB row-locking (our only target); a different
            // DBAL platform may ignore the lock and reopen the race.
            $maxRow = $this->connection->createQueryBuilder()
                ->select('COALESCE(MAX(round_number), 0) AS max_number')
                ->from('wp_scs_rounds')
                ->where('season_id = :season_id')
                ->setParameter('season_id', $season_id)
                ->forUpdate()
                ->fetchAssociative();

            $nextNumber = ((int)($maxRow['max_number'] ?? 0)) + 1;

            $this->connection->insert('wp_scs_rounds', [
                'season_id'    => $season_id,
                'round_number' => $nextNumber,
                'date'         => $date,
                'status'       => RoundStatus::Draft->value,
            ]);

            return $this->findById((int)$this->connection->lastInsertId());
        });
    }

    public function updateStatus(int $id, RoundStatus $status): void
    {
        $this->connection->update('wp_scs_rounds', [ 'status' => $status->value ], [ 'id' => $id ]);
    }

    public function update(int $id, array $data): void
    {
        $this->connection->update('wp_scs_rounds', $data, [ 'id' => $id ]);
    }

    private function hydrate(array $row): Round
    {
        return new Round(
            id:           (int)$row['id'],
            season_id:    (int)$row['season_id'],
            round_number: (int)$row['round_number'],
            date:         $row['date'] !== null ? new \DateTimeImmutable($row['date']) : null,
            status:       RoundStatus::from($row['status']),
            created_at:   new \DateTimeImmutable($row['created_at']),
        );
    }
}
