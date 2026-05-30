<?php

declare(strict_types=1);

namespace SCS\Repository;

use Doctrine\DBAL\Connection;
use SCS\Entity\Enum\GameResult;
use SCS\Entity\Game;

class GameRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findByRound(int $round_id): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('wp_scs_games')
            ->where('round_id = :round_id')
            ->setParameter('round_id', $round_id)
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findById(int $id): ?Game
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('wp_scs_games')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    public function findBySeasonPlayer(int $season_player_id): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('g.*')
            ->from('wp_scs_games', 'g')
            ->join('g', 'wp_scs_rounds', 'r', 'g.round_id = r.id')
            ->where('g.white_season_player_id = :sp_id')
            ->orWhere('g.black_season_player_id = :sp_id')
            ->setParameter('sp_id', $season_player_id)
            ->orderBy('r.round_number', 'ASC')
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function create(int $round_id, int $white_season_player_id, int $black_season_player_id): Game
    {
        $this->connection->insert('wp_scs_games', [
            'round_id'               => $round_id,
            'white_season_player_id' => $white_season_player_id,
            'black_season_player_id' => $black_season_player_id,
            'result'                 => null,
        ]);

        return $this->findById((int)$this->connection->lastInsertId());
    }

    public function updateResult(int $id, ?GameResult $result): void
    {
        $this->connection->update('wp_scs_games', [
            'result' => $result?->value,
        ], [ 'id' => $id ]);
    }

    public function deleteByRound(int $round_id): void
    {
        $this->connection->delete('wp_scs_games', [ 'round_id' => $round_id ]);
    }

    private function hydrate(array $row): Game
    {
        return new Game(
            id:                      (int)$row['id'],
            round_id:                (int)$row['round_id'],
            white_season_player_id:  (int)$row['white_season_player_id'],
            black_season_player_id:  (int)$row['black_season_player_id'],
            result:                  $row['result'] !== null ? GameResult::from($row['result']) : null,
        );
    }
}
