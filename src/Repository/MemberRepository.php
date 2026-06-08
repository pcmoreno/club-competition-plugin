<?php

declare(strict_types=1);

namespace SCS\Repository;

use Doctrine\DBAL\Connection;
use SCS\Entity\Enum\MemberStatus;
use SCS\Entity\Member;

class MemberRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $id): ?Member
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('wp_scs_members')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    /** @return Member[] */
    public function findAll(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('wp_scs_members')
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findByEmail(string $email): ?Member
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('wp_scs_members')
            ->where('email = :email')
            ->setParameter('email', $email)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByPlayerId(int $player_id): ?Member
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('wp_scs_members')
            ->where('player_id = :player_id')
            ->setParameter('player_id', $player_id)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByInviteToken(string $token): ?Member
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('wp_scs_members')
            ->where('invite_token = :token')
            ->setParameter('token', $token)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByResetToken(string $token): ?Member
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('wp_scs_members')
            ->where('reset_token = :token')
            ->setParameter('token', $token)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    public function create(int $player_id, string $email, string $invite_token, \DateTimeImmutable $invite_expires_at): Member
    {
        $this->connection->insert('wp_scs_members', [
            'player_id'         => $player_id,
            'email'             => $email,
            'invite_token'      => $invite_token,
            'invite_expires_at' => $invite_expires_at->format('Y-m-d H:i:s'),
            'status'            => MemberStatus::Invited->value,
        ]);

        return $this->findById((int)$this->connection->lastInsertId());
    }

    public function update(int $id, array $data): void
    {
        $this->connection->update('wp_scs_members', $data, [ 'id' => $id ]);
    }

    private function hydrate(array $row): Member
    {
        return new Member(
            id:                 (int)$row['id'],
            player_id:          (int)$row['player_id'],
            email:              $row['email'],
            password_hash:      $row['password_hash'],
            invite_token:       $row['invite_token'],
            invite_expires_at:  $row['invite_expires_at'] !== null ? new \DateTimeImmutable($row['invite_expires_at']) : null,
            reset_token:        $row['reset_token'],
            reset_expires_at:   $row['reset_expires_at'] !== null ? new \DateTimeImmutable($row['reset_expires_at']) : null,
            status:             MemberStatus::from($row['status']),
            created_at:         new \DateTimeImmutable($row['created_at']),
        );
    }
}
