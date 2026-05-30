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
            ->from('wp_scs_players')
            ->orderBy('name', 'ASC')
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findActive(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('wp_scs_players')
            ->where('active = 1')
            ->orderBy('name', 'ASC')
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findById(int $id): ?Player
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('wp_scs_players')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    public function create(string $name, ?string $knsb_id, ?int $knsb_elo, ?string $gender, ?string $date_of_birth): Player
    {
        $this->connection->insert('wp_scs_players', [
            'name'          => $name,
            'knsb_id'       => $knsb_id,
            'knsb_elo'      => $knsb_elo,
            'gender'        => $gender,
            'date_of_birth' => $date_of_birth,
            'active'        => 1,
        ]);

        return $this->findById((int)$this->connection->lastInsertId());
    }

    public function update(int $id, array $data): void
    {
        $this->connection->update('wp_scs_players', $data, [ 'id' => $id ]);
    }

    public function deactivate(int $id): void
    {
        $this->connection->update('wp_scs_players', [ 'active' => 0 ], [ 'id' => $id ]);
    }

    private function hydrate(array $row): Player
    {
        return new Player(
            id:            (int)$row['id'],
            name:          $row['name'],
            knsb_id:       $row['knsb_id'],
            knsb_elo:      $row['knsb_elo'] !== null ? (int)$row['knsb_elo'] : null,
            gender:        $row['gender'] !== null ? Gender::from($row['gender']) : null,
            date_of_birth: $row['date_of_birth'] !== null ? new \DateTimeImmutable($row['date_of_birth']) : null,
            active:        (bool)$row['active'],
            created_at:    new \DateTimeImmutable($row['created_at']),
        );
    }
}
