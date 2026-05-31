<?php

declare(strict_types=1);

namespace SCS\Services;

use SCS\Repository\PlayerRepository;
use SCS\Repository\SeasonPlayerRepository;

/**
 * Resolves a season's enrolled players to display-ready info, keyed by
 * season_player id. Used to render games, rosters and standings without the
 * client joining season_players ↔ players: a player's name comes from the
 * roster, their category/elo from the season enrollment.
 */
class PlayerDisplayService
{
    public function __construct(
        private readonly SeasonPlayerRepository $seasonPlayerRepository,
        private readonly PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @return array<int, array{season_player_id: int, player_id: int, name: ?string, category: ?string, elo: int}>
     */
    public function mapForSeason(int $season_id): array
    {
        $names = [];
        foreach ($this->playerRepository->findAll() as $player) {
            $names[$player->id] = $player->name;
        }

        $map = [];
        foreach ($this->seasonPlayerRepository->findBySeason($season_id) as $sp) {
            $map[$sp->id] = [
                'season_player_id' => $sp->id,
                'player_id'        => $sp->player_id,
                'name'             => $names[$sp->player_id] ?? null,
                'category'         => $sp->category,
                'elo'              => $sp->elo_rating,
            ];
        }

        return $map;
    }
}
