<?php

declare(strict_types=1);

namespace SCS\Repository;

use Doctrine\DBAL\Connection;
use SCS\Entity\StandingsSnapshot;

class StandingsSnapshotRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return StandingsSnapshot[] ordered by rank */
    public function findByRound(int $round_id): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'standings_snapshots')
            ->where('round_id = :round_id')
            ->setParameter('round_id', $round_id)
            ->orderBy('rank_position', 'ASC')
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Snapshot rows for a round, scoped to a season. Returns [] when the round
     * does not belong to the season — prevents reading another (concurrently
     * active) season's standings via ?round= on this season's URL.
     *
     * @return StandingsSnapshot[] ordered by rank
     */
    public function findByRoundForSeason(int $round_id, int $season_id): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'standings_snapshots')
            ->where('round_id = :round_id')
            ->andWhere('season_id = :season_id')
            ->setParameter('round_id', $round_id)
            ->setParameter('season_id', $season_id)
            ->orderBy('rank_position', 'ASC')
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * The current standings: the snapshot of the most-recently-completed round
     * that has one. Empty until at least one round is complete.
     *
     * @return StandingsSnapshot[]
     */
    public function findLatestForSeason(int $season_id): array
    {
        // "Latest" means the highest round_number that has a snapshot — not the
        // highest round_id. A deleted-and-recreated or out-of-order round would
        // break id ordering, so resolve it through the rounds table instead.
        $latestRoundId = $this->connection->createQueryBuilder()
            ->select('s.round_id')
            ->from(SCS_TABLE_PREFIX . 'standings_snapshots', 's')
            ->innerJoin('s', SCS_TABLE_PREFIX . 'rounds', 'r', 's.round_id = r.id')
            ->where('s.season_id = :season_id')
            ->setParameter('season_id', $season_id)
            ->orderBy('r.round_number', 'DESC')
            ->setMaxResults(1)
            ->fetchOne();

        if ($latestRoundId === false || $latestRoundId === null) {
            return [];
        }

        return $this->findByRound((int)$latestRoundId);
    }

    /**
     * The round_id of the snapshot-bearing round immediately before $round_id
     * (by round_number) in the season, or null if it's the first. Used to diff
     * standings for movers (▲/▼). Skips rounds without a snapshot.
     */
    public function findPreviousRoundId(int $season_id, int $round_id): ?int
    {
        $previous = $this->connection->createQueryBuilder()
            ->select('s.round_id')
            ->from(SCS_TABLE_PREFIX . 'standings_snapshots', 's')
            ->innerJoin('s', SCS_TABLE_PREFIX . 'rounds', 'r', 's.round_id = r.id')
            ->where('s.season_id = :season_id')
            ->andWhere('r.round_number < (SELECT round_number FROM ' . SCS_TABLE_PREFIX . 'rounds WHERE id = :round_id)')
            ->setParameter('season_id', $season_id)
            ->setParameter('round_id', $round_id)
            ->orderBy('r.round_number', 'DESC')
            ->setMaxResults(1)
            ->fetchOne();

        return $previous === false || $previous === null ? null : (int)$previous;
    }

    public function findByRoundAndSeasonPlayer(int $round_id, int $season_player_id): ?StandingsSnapshot
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(SCS_TABLE_PREFIX . 'standings_snapshots')
            ->where('round_id = :round_id')
            ->andWhere('season_player_id = :sp_id')
            ->setParameter('round_id', $round_id)
            ->setParameter('sp_id', $season_player_id)
            ->fetchAssociative();

        return $row ? $this->hydrate($row) : null;
    }

    public function create(
        int $season_id,
        int $round_id,
        int $season_player_id,
        int $rank,
        ?int $keizer_score,
        float $classical_points,
        int $wins,
        int $draws,
        int $losses,
        int $games,
        int $byes,
        int $color_balance,
        ?int $tpr,
    ): void {
        $this->connection->insert(SCS_TABLE_PREFIX . 'standings_snapshots', [
            'season_id'        => $season_id,
            'round_id'         => $round_id,
            'season_player_id' => $season_player_id,
            'rank_position'    => $rank,
            'keizer_score'     => $keizer_score,
            'classical_points' => $classical_points,
            'wins'             => $wins,
            'draws'            => $draws,
            'losses'           => $losses,
            'games'            => $games,
            'byes'             => $byes,
            'color_balance'    => $color_balance,
            'tpr'              => $tpr,
        ]);
    }

    public function deleteByRound(int $round_id): void
    {
        $this->connection->delete(SCS_TABLE_PREFIX . 'standings_snapshots', [ 'round_id' => $round_id ]);
    }

    public function deleteBySeason(int $season_id): void
    {
        $this->connection->delete(SCS_TABLE_PREFIX . 'standings_snapshots', [ 'season_id' => $season_id ]);
    }

    private function hydrate(array $row): StandingsSnapshot
    {
        return new StandingsSnapshot(
            id:               (int)$row['id'],
            season_id:        (int)$row['season_id'],
            round_id:         (int)$row['round_id'],
            season_player_id: (int)$row['season_player_id'],
            rank:             (int)$row['rank_position'],
            keizer_score:     $row['keizer_score'] !== null ? (int)$row['keizer_score'] : null,
            classical_points: (float)$row['classical_points'],
            wins:             (int)$row['wins'],
            draws:            (int)$row['draws'],
            losses:           (int)$row['losses'],
            games:            (int)$row['games'],
            byes:             (int)$row['byes'],
            color_balance:    (int)$row['color_balance'],
            tpr:              $row['tpr'] !== null ? (int)$row['tpr'] : null,
        );
    }
}
