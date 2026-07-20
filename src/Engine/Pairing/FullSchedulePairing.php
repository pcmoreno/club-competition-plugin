<?php

declare(strict_types=1);

namespace SCS\Engine\Pairing;

use SCS\Entity\Season;

// Produces the whole fixture up front from a locked roster (round-robin). Score-independent.
interface FullSchedulePairing extends PairingEngineInterface
{
    /**
     * @param list<\SCS\Entity\SeasonPlayer> $roster locked at tournament start
     * @return list<PairingResult> one per round
     */
    public function pairSchedule(Season $season, array $roster): array;
}
