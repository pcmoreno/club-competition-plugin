<?php

declare(strict_types=1);

namespace SCS\Repository;

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
            ->from('wp_scs_season_players')
            ->where('season_id = :season_id')
            ->setParameter('season_id', $season_id)
            ->orderBy('enrolled_at', 'ASC')
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findById(int $id): ?SeasonPlayer
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('wp_scs_season_players')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    public function findBySeasonAndPlayer(int $season_id, int $player_id): ?SeasonPlayer
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('wp_scs_season_players')
            ->where('season_id = :season_id')
            ->andWhere('player_id = :player_id')
            ->setParameter('season_id', $season_id)
            ->setParameter('player_id', $player_id)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    public function create(int $season_id, int $player_id, string $category, int $elo_rating): SeasonPlayer
    {
        $this->connection->insert('wp_scs_season_players', [
            'season_id'  => $season_id,
            'player_id'  => $player_id,
            'category'   => $category,
            'elo_rating' => $elo_rating,
        ]);

        return $this->findById((int)$this->connection->lastInsertId());
    }

    public function update(int $id, array $data): void
    {
        $this->connection->update('wp_scs_season_players', $data, [ 'id' => $id ]);
    }

    public function delete(int $id): void
    {
        $this->connection->delete('wp_scs_season_players', [ 'id' => $id ]);
    }

    private function hydrate(array $row): SeasonPlayer
    {
        return new SeasonPlayer(
            id:          (int)$row['id'],
            season_id:   (int)$row['season_id'],
            player_id:   (int)$row['player_id'],
            category:    $row['category'],
            elo_rating:  (int)$row['elo_rating'],
            enrolled_at: new \DateTimeImmutable($row['enrolled_at']),
        );
    }
}
