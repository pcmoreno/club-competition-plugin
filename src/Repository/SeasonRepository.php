<?php

declare(strict_types=1);

namespace SCS\Repository;

use Doctrine\DBAL\Connection;
use SCS\Entity\Enum\PairingSystem;
use SCS\Entity\Enum\SeasonStatus;
use SCS\Entity\Season;

class SeasonRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findAll(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('wp_scs_seasons')
            ->orderBy('created_at', 'DESC')
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findById(int $id): ?Season
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('wp_scs_seasons')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    public function findActive(): ?Season
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('wp_scs_seasons')
            ->where('status = :status')
            ->setParameter('status', SeasonStatus::Active->value)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    public function create(string $name, ?string $location, ?string $start_date, ?string $end_date, PairingSystem $pairing_system, array $categories): Season
    {
        $this->connection->insert('wp_scs_seasons', [
            'name'           => $name,
            'location'       => $location,
            'start_date'     => $start_date,
            'end_date'       => $end_date,
            'pairing_system' => $pairing_system->value,
            'status'         => SeasonStatus::Preparation->value,
            'categories'     => json_encode($categories),
        ]);

        return $this->findById((int)$this->connection->lastInsertId());
    }

    public function update(int $id, array $data): void
    {
        $this->connection->update('wp_scs_seasons', $data, [ 'id' => $id ]);
    }

    public function updateStatus(int $id, SeasonStatus $status): void
    {
        $this->connection->update('wp_scs_seasons', [ 'status' => $status->value ], [ 'id' => $id ]);
    }

    private function hydrate(array $row): Season
    {
        return new Season(
            id:             (int)$row['id'],
            name:           $row['name'],
            location:       $row['location'],
            start_date:     $row['start_date'] !== null ? new \DateTimeImmutable($row['start_date']) : null,
            end_date:       $row['end_date'] !== null ? new \DateTimeImmutable($row['end_date']) : null,
            pairing_system: PairingSystem::from($row['pairing_system']),
            status:         SeasonStatus::from($row['status']),
            categories:     json_decode($row['categories'] ?? '[]', true),
            created_at:     new \DateTimeImmutable($row['created_at']),
        );
    }
}
