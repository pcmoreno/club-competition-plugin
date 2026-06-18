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
            ->orderBy('board', 'ASC')
            ->addOrderBy('id', 'ASC')
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

    public function create(int $round_id, int $white_season_player_id, int $black_season_player_id, ?int $board = null, ?GameResult $result = null): Game
    {
        $this->connection->insert('wp_scs_games', [
            'round_id'               => $round_id,
            'board'                  => $board,
            'white_season_player_id' => $white_season_player_id,
            'black_season_player_id' => $black_season_player_id,
            'result'                 => $result?->value,
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

    public function deleteBySeason(int $season_id): void
    {
        // Games reference a round, not the season directly — delete all games
        // whose round belongs to the season. Multi-table DELETE isn't expressible
        // via the query builder, so this is a bound raw statement.
        $this->connection->executeStatement(
            'DELETE g FROM wp_scs_games g JOIN wp_scs_rounds r ON r.id = g.round_id WHERE r.season_id = ?',
            [$season_id]
        );
    }

    private function hydrate(array $row): Game
    {
        return new Game(
            id:                      (int)$row['id'],
            round_id:                (int)$row['round_id'],
            board:                   $row['board'] !== null ? (int)$row['board'] : null,
            white_season_player_id:  (int)$row['white_season_player_id'],
            black_season_player_id:  (int)$row['black_season_player_id'],
            result:                  $row['result'] !== null ? GameResult::from($row['result']) : null,
        );
    }
}
