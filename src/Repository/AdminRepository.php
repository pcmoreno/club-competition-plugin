<?php

declare(strict_types=1);

namespace SCS\Repository;

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
            ->from('wp_scs_admins')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByEmail(string $email): ?Admin
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('wp_scs_admins')
            ->where('email = :email')
            ->setParameter('email', $email)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    public function create(string $name, string $email, string $password_hash): Admin
    {
        $this->connection->insert('wp_scs_admins', [
            'name'          => $name,
            'email'         => $email,
            'password_hash' => $password_hash,
            'status'        => AdminStatus::Active->value,
        ]);

        return $this->findById((int)$this->connection->lastInsertId());
    }

    public function update(int $id, array $data): void
    {
        $this->connection->update('wp_scs_admins', $data, [ 'id' => $id ]);
    }

    private function hydrate(array $row): Admin
    {
        return new Admin(
            id:            (int)$row['id'],
            name:          $row['name'],
            email:         $row['email'],
            password_hash: $row['password_hash'],
            status:        AdminStatus::from($row['status']),
            created_at:    new \DateTimeImmutable($row['created_at']),
        );
    }
}
