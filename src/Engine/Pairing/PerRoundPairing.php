<?php

declare(strict_types=1);

namespace SCS\Engine\Pairing;

use SCS\Entity\Season;

// Pairs one round at a time; may read the current standings (Keizer, Swiss). Manual is a no-op variant.
interface PerRoundPairing extends PairingEngineInterface
{
    /**
     * @param list<\SCS\Entity\SeasonPlayer>       $roster    present players for the round
     * @param list<\SCS\Entity\Game>               $history   prior games (no-repeat, colour balance)
     * @param list<\SCS\Entity\StandingsSnapshot>  $standings current standings (score proximity)
     */
    public function pairNextRound(Season $season, array $roster, array $history, array $standings): PairingResult;
}
