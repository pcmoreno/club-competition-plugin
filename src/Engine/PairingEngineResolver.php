<?php

declare(strict_types=1);

namespace SCS\Engine;

use SCS\Engine\Pairing\ManualPairing;
use SCS\Engine\Pairing\PairingEngineInterface;
use SCS\Entity\Enum\PairingSystem;
use SCS\Entity\Season;

// Builds the pairing engine for a season from its pairing system.
final class PairingEngineResolver
{
    public function resolve(Season $season): PairingEngineInterface
    {
        return match ($season->pairing_system) {
            PairingSystem::Manual => new ManualPairing(),
            default => throw new \RuntimeException(
                sprintf('Pairing engine is not implemented for "%s" yet.', $season->pairing_system->value)
            ),
        };
    }
}
