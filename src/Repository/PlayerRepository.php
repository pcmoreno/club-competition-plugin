<?php

declare(strict_types=1);

namespace SCS\Repository;

use Doctrine\DBAL\Connection;
use SCS\Entity\Enum\Gender;
use SCS\Entity\Player;

class PlayerRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findAll(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'players')
            ->orderBy('name', 'ASC')
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findActive(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'players')
            ->where('active = 1')
            ->orderBy('name', 'ASC')
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findById(int $id): ?Player
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'players')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByName(string $name): ?Player
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'players')
            ->where('name = :name')
            ->setParameter('name', $name)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    public function create(string $name, ?string $knsb_id, ?int $knsb_elo, ?string $gender, ?int $birth_year): Player
    {
        $this->connection->insert(SCS_TABLE_PREFIX . 'players', [
            'name'       => $name,
            'knsb_id'    => $knsb_id,
            'knsb_elo'   => $knsb_elo,
            'gender'     => $gender,
            'birth_year' => $birth_year,
            'active'     => 1,
        ]);

        return $this->findById((int)$this->connection->lastInsertId());
    }

    public function update(int $id, array $data): void
    {
        $this->connection->update(SCS_TABLE_PREFIX . 'players', $data, [ 'id' => $id ]);
    }

    public function deactivate(int $id): void
    {
        $this->connection->update(SCS_TABLE_PREFIX . 'players', [ 'active' => 0 ], [ 'id' => $id ]);
    }

    /**
     * Apply authoritative KNSB data to a player: name, birth year, and rating,
     * stamping when it was synced. KNSB is treated as the source of truth, so
     * name + birth_year overwrite whatever was there (correcting manual typos).
     * knsb_synced_at tracks the last successful sync (distinct from created_at,
     * which is row creation).
     *
     * @throws \Doctrine\DBAL\Exception\UniqueConstraintViolationException when
     *   the name collides with another player (players.name is UNIQUE)
     */
    public function applyKnsbData(int $id, string $name, ?int $birth_year, int $knsb_elo, string $synced_at): void
    {
        $this->connection->update(
            SCS_TABLE_PREFIX . 'players',
            [
                'name'           => $name,
                'birth_year'     => $birth_year,
                'knsb_elo'       => $knsb_elo,
                'knsb_synced_at' => $synced_at,
            ],
            [ 'id' => $id ]
        );
    }

    private function hydrate(array $row): Player
    {
        return new Player(
            id:            (int)$row['id'],
            name:          $row['name'],
            knsb_id:       $row['knsb_id'],
            knsb_elo:      $row['knsb_elo'] !== null ? (int)$row['knsb_elo'] : null,
            gender:        $row['gender'] !== null ? Gender::from($row['gender']) : null,
            birth_year:    $row['birth_year'] !== null ? (int)$row['birth_year'] : null,
            active:        (bool)$row['active'],
            created_at:    new \DateTimeImmutable($row['created_at']),
            knsb_synced_at: $row['knsb_synced_at'] !== null ? new \DateTimeImmutable($row['knsb_synced_at']) : null,
        );
    }
}
