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
            ->from(SCS_TABLE_PREFIX . 'members')
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
            ->from(SCS_TABLE_PREFIX . 'members')
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findByEmail(string $email): ?Member
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'members')
            ->where('email = :email')
            ->setParameter('email', $email)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByPlayerId(int $player_id): ?Member
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'members')
            ->where('player_id = :player_id')
            ->setParameter('player_id', $player_id)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    /** invite_token stores a SHA-256 hash — pass the hash, not the raw token. */
    public function findByInviteTokenHash(string $tokenHash): ?Member
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'members')
            ->where('invite_token = :tokenHash')
            ->setParameter('tokenHash', $tokenHash)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    /** reset_token stores a SHA-256 hash — pass the hash, not the raw token. */
    public function findByResetTokenHash(string $tokenHash): ?Member
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'members')
            ->where('reset_token = :tokenHash')
            ->setParameter('tokenHash', $tokenHash)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    /** $invite_token_hash is a SHA-256 hash — caller emails the raw token, never persists it. */
    public function create(int $player_id, string $email, string $invite_token_hash, \DateTimeImmutable $invite_expires_at): Member
    {
        $this->connection->insert(SCS_TABLE_PREFIX . 'members', [
            'player_id'         => $player_id,
            'email'             => $email,
            'invite_token'      => $invite_token_hash,
            'invite_expires_at' => $invite_expires_at->format('Y-m-d H:i:s'),
            'status'            => MemberStatus::Invited->value,
        ]);

        return $this->findById((int)$this->connection->lastInsertId());
    }

    public function update(int $id, array $data): void
    {
        $this->connection->update(SCS_TABLE_PREFIX . 'members', $data, [ 'id' => $id ]);
    }

    public function delete(int $id): void
    {
        $this->connection->delete(SCS_TABLE_PREFIX . 'members', [ 'id' => $id ]);
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
            token_valid_after:  $row['token_valid_after'] !== null ? new \DateTimeImmutable($row['token_valid_after']) : null,
        );
    }
}
