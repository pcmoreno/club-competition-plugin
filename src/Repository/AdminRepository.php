<?php

declare(strict_types=1);

namespace SCS\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SCS\Entity\Admin;
use SCS\Entity\Enum\AdminStatus;

class AdminRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $id): ?Admin
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'admins')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByEmail(string $email): ?Admin
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'admins')
            ->where('email = :email')
            ->setParameter('email', $email)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Every active admin — the recipients for notifications that go to "the
     * admins" rather than one person (a member declaring they can't play).
     *
     * @return list<Admin>
     */
    public function findAllActive(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'admins')
            ->where('status = :status')
            ->setParameter('status', AdminStatus::Active->value)
            ->orderBy('name', 'ASC')
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * Admins by id, in the order they were given — the caller's order is the
     * one the tournament stored, and re-sorting it here would lose it.
     *
     * @param list<int> $ids
     * @return list<Admin>
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'admins')
            ->where('id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->fetchAllAssociative();

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int)$row['id']] = $this->hydrate($row);
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    public function countAll(): int
    {
        return (int)$this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(SCS_TABLE_PREFIX . 'admins')
            ->fetchOne();
    }

    public function create(string $name, string $email, string $password_hash): Admin
    {
        $this->connection->insert(SCS_TABLE_PREFIX . 'admins', [
            'name'          => $name,
            'email'         => $email,
            'password_hash' => $password_hash,
            'status'        => AdminStatus::Active->value,
        ]);

        return $this->findById((int)$this->connection->lastInsertId());
    }

    public function update(int $id, array $data): void
    {
        $this->connection->update(SCS_TABLE_PREFIX . 'admins', $data, [ 'id' => $id ]);
    }

    private function hydrate(array $row): Admin
    {
        return new Admin(
            id:                (int)$row['id'],
            name:              $row['name'],
            email:             $row['email'],
            password_hash:     $row['password_hash'],
            status:            AdminStatus::from($row['status']),
            created_at:        new \DateTimeImmutable($row['created_at']),
            token_valid_after: $row['token_valid_after'] !== null ? new \DateTimeImmutable($row['token_valid_after']) : null,
        );
    }
}
