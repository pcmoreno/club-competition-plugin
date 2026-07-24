<?php

declare(strict_types=1);

namespace SCS\Engine\Pairing;

// What an engine produces for a round: the games to create, plus the byes to record in attendance.
final class PairingResult
{
    /**
     * @param list<array{white:int,black:int,board:int}>          $pairings
     * @param list<array{season_player_id:int,bye_type:string}>   $byes
     */
    public function __construct(
        public readonly array $pairings,
        public readonly array $byes,
    ) {
    }

    public static function empty(): self
    {
        return new self([], []);
    }
}
