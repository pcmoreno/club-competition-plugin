<?php

declare(strict_types=1);

namespace SCS\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SCS\Entity\Attendance;
use SCS\Entity\Enum\AttendanceStatus;
use SCS\Entity\Enum\ByeType;

class AttendanceRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return list<Attendance> */
    public function findByRound(int $round_id): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'attendance')
            ->where('round_id = :round_id')
            ->setParameter('round_id', $round_id)
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    public function findByRoundAndSeasonPlayer(int $round_id, int $season_player_id): ?Attendance
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'attendance')
            ->where('round_id = :round_id')
            ->andWhere('season_player_id = :season_player_id')
            ->setParameter('round_id', $round_id)
            ->setParameter('season_player_id', $season_player_id)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Every attendance row for one enrolled player across the season, keyed by
     * round id — lets a player's tournament detail mark the rounds they sat out
     * (byes) without an N+1 of per-round lookups.
     *
     * @return array<int, Attendance> keyed by round_id
     */
    public function findBySeasonPlayer(int $season_player_id): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'attendance')
            ->where('season_player_id = :season_player_id')
            ->setParameter('season_player_id', $season_player_id)
            ->fetchAllAssociative();

        $byRound = [];
        foreach ($rows as $row) {
            $attendance              = $this->hydrate($row);
            $byRound[$attendance->round_id] = $attendance;
        }

        return $byRound;
    }

    public function save(int $round_id, int $season_player_id, AttendanceStatus $status, ?string $bye_type): void
    {
        $existing = $this->findByRoundAndSeasonPlayer($round_id, $season_player_id);

        $data = [
            'status'   => $status->value,
            'bye_type' => $bye_type,
        ];

        if ($existing) {
            $this->connection->update(SCS_TABLE_PREFIX . 'attendance', $data, [
                'round_id'         => $round_id,
                'season_player_id' => $season_player_id,
            ]);
        } else {
            $this->connection->insert(SCS_TABLE_PREFIX . 'attendance', array_merge($data, [
                'round_id'         => $round_id,
                'season_player_id' => $season_player_id,
            ]));
        }
    }

    /**
     * Drop one player's attendance row for a round, back to "nothing recorded".
     * Used when a member withdraws a declared absence: there is no "present"
     * to set — presence is what the admin asserts when finalising the round.
     */
    public function delete(int $round_id, int $season_player_id): void
    {
        $this->connection->delete(SCS_TABLE_PREFIX . 'attendance', [
            'round_id'         => $round_id,
            'season_player_id' => $season_player_id,
        ]);
    }

    // Scoped to one type so regenerating a round clears the byes the engine
    // assigned last time without touching an absence the admin recorded.
    public function deleteByRoundAndByeType(int $round_id, ByeType $bye_type): void
    {
        $this->connection->delete(SCS_TABLE_PREFIX . 'attendance', [
            'round_id' => $round_id,
            'bye_type' => $bye_type->value,
        ]);
    }

    // Every bye key a season's rounds hold, so settings can refuse to drop one in use.
    /** @return list<string> */
    public function findByeTypesBySeason(int $season_id): array
    {
        $keys = $this->connection->createQueryBuilder()
            ->select('DISTINCT a.bye_type')
            ->from(SCS_TABLE_PREFIX . 'attendance', 'a')
            ->innerJoin('a', SCS_TABLE_PREFIX . 'rounds', 'r', 'r.id = a.round_id')
            ->where('r.season_id = :season')
            ->andWhere('a.bye_type IS NOT NULL')
            ->setParameter('season', $season_id)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map('strval', $keys);
    }

    public function deleteBySeason(int $season_id): void
    {
        // Attendance references a round, not the season directly. Multi-table
        // DELETE isn't expressible via the query builder, so this is a bound
        // raw statement.
        $this->connection->executeStatement(
            'DELETE a FROM ' . SCS_TABLE_PREFIX . 'attendance a JOIN ' . SCS_TABLE_PREFIX . 'rounds r ON r.id = a.round_id WHERE r.season_id = ?',
            [$season_id]
        );
    }

    // Scoped to the rounds handed in, so a caller can delete exactly the rows it
    // looked at rather than everything the season happens to hold.
    /** @param list<int> $ids */
    public function deleteByRounds(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->connection->createQueryBuilder()
            ->delete(SCS_TABLE_PREFIX . 'attendance')
            ->where('round_id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->executeStatement();
    }

    /**
     * @param list<array{season_player_id: int, status: AttendanceStatus, bye_type: ?string}> $entries
     */
    public function saveMany(int $round_id, array $entries): void
    {
        $this->connection->transactional(function () use ($round_id, $entries): void {
            foreach ($entries as $entry) {
                $this->save($round_id, $entry['season_player_id'], $entry['status'], $entry['bye_type']);
            }
        });
    }

    private function hydrate(array $row): Attendance
    {
        return new Attendance(
            id:               (int)$row['id'],
            round_id:         (int)$row['round_id'],
            season_player_id: (int)$row['season_player_id'],
            status:           AttendanceStatus::from($row['status']),
            bye_type:         $row['bye_type'] !== null ? (string)$row['bye_type'] : null,
        );
    }
}
