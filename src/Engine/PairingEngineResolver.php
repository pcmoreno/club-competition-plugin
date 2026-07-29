<?php

declare(strict_types=1);

namespace SCS\Engine;

use SCS\Engine\Pairing\ManualPairing;
use SCS\Engine\Pairing\PairingEngineInterface;
use SCS\Entity\Enum\PairingSystem;
use SCS\Entity\Season;
use SCS\Exception\ConflictException;

// Builds the pairing engine for a season from its pairing system.
final class PairingEngineResolver
{
    public function resolve(Season $season): PairingEngineInterface
    {
        return match ($season->pairing_system) {
            PairingSystem::Manual => new ManualPairing(),
            default => throw new ConflictException(
                sprintf('Automatic pairing is not available for %s yet — build the board by hand.', $season->pairing_system->value)
            ),
        };
    }
}
