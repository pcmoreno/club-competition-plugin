<?php

declare(strict_types=1);

namespace SCS\Entity;

use SCS\Entity\Enum\GameResult;
use SCS\Entity\Enum\TimeControl;

class Game
{
    public function __construct(
        public readonly int $id,
        public readonly int $round_id,
        public readonly ?int $board,
        public readonly int $white_season_player_id,
        public readonly int $black_season_player_id,
        public readonly ?GameResult $result,
        public readonly TimeControl $time_control = TimeControl::Classical,
    ) {
    }
}
