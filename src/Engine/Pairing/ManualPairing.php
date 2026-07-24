<?php

declare(strict_types=1);

namespace SCS\Engine\Pairing;

use SCS\Entity\Season;

// Per-round in cadence, but produces nothing — the admin enters every pairing by hand.
final class ManualPairing implements PerRoundPairing
{
    public function pairNextRound(Season $season, array $roster, array $history, array $standings): PairingResult
    {
        return PairingResult::empty();
    }
}
