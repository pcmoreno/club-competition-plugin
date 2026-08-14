<?php

declare(strict_types=1);

namespace SCS\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SCS\Entity\SeasonPlayer;

class SeasonPlayerRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findBySeason(int $season_id): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'season_players')
            ->where('season_id = :season_id')
            ->setParameter('season_id', $season_id)
            ->orderBy('enrolled_at', 'ASC')
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    /** @return SeasonPlayer[] */
    public function findByPlayer(int $player_id): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'season_players')
            ->where('player_id = :player_id')
            ->setParameter('player_id', $player_id)
            ->orderBy('enrolled_at', 'DESC')
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    // Enrolled-player count per season, keyed by season_id — one query for the list.
    /** @return array<int,int> */
    public function countBySeason(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('season_id', 'COUNT(*) AS total')
            ->from(SCS_TABLE_PREFIX . 'season_players')
            ->groupBy('season_id')
            ->fetchAllAssociative();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int)$row['season_id']] = (int)$row['total'];
        }

        return $counts;
    }

    public function findById(int $id): ?SeasonPlayer
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'season_players')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    public function findBySeasonAndPlayer(int $season_id, int $player_id): ?SeasonPlayer
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'season_players')
            ->where('season_id = :season_id')
            ->andWhere('player_id = :player_id')
            ->setParameter('season_id', $season_id)
            ->setParameter('player_id', $player_id)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    public function create(int $season_id, int $player_id, ?string $category, int $elo_rating): SeasonPlayer
    {
        $this->connection->insert(SCS_TABLE_PREFIX . 'season_players', [
            'season_id'  => $season_id,
            'player_id'  => $player_id,
            'category'   => $category,
            'elo_rating' => $elo_rating,
        ]);

        return $this->findById((int)$this->connection->lastInsertId());
    }

    public function update(int $id, array $data): void
    {
        $this->connection->update(SCS_TABLE_PREFIX . 'season_players', $data, [ 'id' => $id ]);
    }

    /**
     * Enrol many players in one transaction — either every insert lands or none
     * does, so a partial bulk enrol can't leave the season half-populated.
     *
     * @param list<array{player_id: int, category: ?string, elo_rating: int}> $entries
     */
    public function createMany(int $season_id, array $entries): void
    {
        $this->connection->transactional(function () use ($season_id, $entries): void {
            foreach ($entries as $entry) {
                $this->connection->insert(SCS_TABLE_PREFIX . 'season_players', [
                    'season_id'  => $season_id,
                    'player_id'  => $entry['player_id'],
                    'category'   => $entry['category'],
                    'elo_rating' => $entry['elo_rating'],
                ]);
            }
        });
    }

    /**
     * Remove many enrolments in one transaction, scoped to the season so a stray
     * player id can't delete another season's row.
     *
     * @param list<int> $player_ids
     */
    public function deleteBySeasonAndPlayers(int $season_id, array $player_ids): void
    {
        if ($player_ids === []) {
            return;
        }

        $this->connection->createQueryBuilder()
            ->delete(SCS_TABLE_PREFIX . 'season_players')
            ->where('season_id = :season_id')
            ->andWhere('player_id IN (:player_ids)')
            ->setParameter('season_id', $season_id)
            ->setParameter('player_ids', $player_ids, ArrayParameterType::INTEGER)
            ->executeStatement();
    }

    /**
     * Apply many category assignments in one transaction (Auto Fill).
     *
     * @param list<array{id: int, category: ?string}> $updates
     */
    public function updateCategories(array $updates): void
    {
        $this->connection->transactional(function () use ($updates): void {
            foreach ($updates as $update) {
                $this->connection->update(
                    SCS_TABLE_PREFIX . 'season_players',
                    [ 'category' => $update['category'] ],
                    [ 'id' => $update['id'] ]
                );
            }
        });
    }

    // Scoped to the season so a stray enrolment id can't flag another season's row.
    /** @param list<int> $ids */
    public function updateDefaultAbsent(int $season_id, array $ids, bool $default_absent): void
    {
        if ($ids === []) {
            return;
        }

        $this->connection->createQueryBuilder()
            ->update(SCS_TABLE_PREFIX . 'season_players')
            ->set('default_absent', ':default_absent')
            ->where('season_id = :season_id')
            ->andWhere('id IN (:ids)')
            ->setParameter('default_absent', $default_absent ? 1 : 0)
            ->setParameter('season_id', $season_id)
            ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
            ->executeStatement();
    }

    public function delete(int $id): void
    {
        $this->connection->delete(SCS_TABLE_PREFIX . 'season_players', [ 'id' => $id ]);
    }

    /**
     * Repoint every one of a player's enrolments onto another player — the
     * mechanical core of a player merge. The caller must have ruled out a shared
     * season first: the (season_id, player_id) unique key would otherwise reject
     * moving an enrolment into a season the target already occupies.
     */
    public function reassignPlayer(int $from_player_id, int $to_player_id): void
    {
        $this->connection->update(
            SCS_TABLE_PREFIX . 'season_players',
            [ 'player_id' => $to_player_id ],
            [ 'player_id' => $from_player_id ]
        );
    }

    public function deleteBySeason(int $season_id): void
    {
        $this->connection->delete(SCS_TABLE_PREFIX . 'season_players', [ 'season_id' => $season_id ]);
    }

    private function hydrate(array $row): SeasonPlayer
    {
        return new SeasonPlayer(
            id:          (int)$row['id'],
            season_id:   (int)$row['season_id'],
            player_id:   (int)$row['player_id'],
            category:    $row['category'] !== null ? (string)$row['category'] : null,
            elo_rating:  (int)$row['elo_rating'],
            enrolled_at: new \DateTimeImmutable($row['enrolled_at']),
            default_absent: (bool)($row['default_absent'] ?? false),
        );
    }
}
