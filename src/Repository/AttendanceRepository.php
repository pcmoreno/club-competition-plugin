<?php

declare(strict_types=1);

namespace SCS\Repository;

use Doctrine\DBAL\Connection;
use SCS\Entity\Attendance;
use SCS\Entity\Enum\AttendanceStatus;
use SCS\Entity\Enum\ByeType;

class AttendanceRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findByRound(int $round_id): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('wp_scs_attendance')
            ->where('round_id = :round_id')
            ->setParameter('round_id', $round_id)
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findByRoundAndSeasonPlayer(int $round_id, int $season_player_id): ?Attendance
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('wp_scs_attendance')
            ->where('round_id = :round_id')
            ->andWhere('season_player_id = :season_player_id')
            ->setParameter('round_id', $round_id)
            ->setParameter('season_player_id', $season_player_id)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    public function save(int $round_id, int $season_player_id, AttendanceStatus $status, ?ByeType $bye_type): void
    {
        $existing = $this->findByRoundAndSeasonPlayer($round_id, $season_player_id);

        $data = [
            'status'   => $status->value,
            'bye_type' => $bye_type?->value,
        ];

        if ($existing) {
            $this->connection->update('wp_scs_attendance', $data, [
                'round_id'         => $round_id,
                'season_player_id' => $season_player_id,
            ]);
        } else {
            $this->connection->insert('wp_scs_attendance', array_merge($data, [
                'round_id'         => $round_id,
                'season_player_id' => $season_player_id,
            ]));
        }
    }

    /**
     * @param list<array{season_player_id: int, status: AttendanceStatus, bye_type: ?ByeType}> $entries
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
            bye_type:         $row['bye_type'] !== null ? ByeType::from($row['bye_type']) : null,
        );
    }
}
