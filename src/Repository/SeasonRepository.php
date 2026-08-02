<?php

declare(strict_types=1);

namespace SCS\Repository;

use Doctrine\DBAL\Connection;
use SCS\Entity\Enum\PairingSystem;
use SCS\Entity\Enum\SeasonStatus;
use SCS\Entity\Enum\TimeControl;
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
            ->from(SCS_TABLE_PREFIX . 'seasons')
            ->orderBy('created_at', 'DESC')
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findById(int $id): ?Season
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'seasons')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Multiple seasons can be active at once (e.g. a league season running
     * alongside a mid-season tournament), so this returns all of them.
     *
     * @return Season[]
     */
    public function findActive(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'seasons')
            ->where('status = :status')
            ->setParameter('status', SeasonStatus::Active->value)
            ->orderBy('created_at', 'DESC')
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findByName(string $name): ?Season
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'seasons')
            ->where('name = :name')
            ->setParameter('name', $name)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    public function create(string $name, ?string $location, ?string $start_date, ?string $end_date, PairingSystem $pairing_system, array $categories, TimeControl $time_control = TimeControl::Classical): Season
    {
        $this->connection->insert(SCS_TABLE_PREFIX . 'seasons', [
            'name'           => $name,
            'location'       => $location,
            'time_control'   => $time_control->value,
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
        $this->connection->update(SCS_TABLE_PREFIX . 'seasons', $data, [ 'id' => $id ]);
    }

    public function updateStatus(int $id, SeasonStatus $status): void
    {
        $this->connection->update(SCS_TABLE_PREFIX . 'seasons', [ 'status' => $status->value ], [ 'id' => $id ]);
    }

    // Only the season row; child rows (rounds/games/etc.) are cleared by the caller.
    public function delete(int $id): void
    {
        $this->connection->delete(SCS_TABLE_PREFIX . 'seasons', [ 'id' => $id ]);
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
            pairing_settings: $this->decodeSettings($row['pairing_settings'] ?? null),
            scoring_settings: $this->decodeSettings($row['scoring_settings'] ?? null),
            display_settings: $this->decodeSettings($row['display_settings'] ?? null),
            time_control:     TimeControl::from($row['time_control']),
        );
    }

    // Null (or an empty column) means "no config yet" — the strategy uses its defaults.
    /** @return array<string,mixed>|null */
    private function decodeSettings(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
