<?php

declare(strict_types=1);

namespace SCS\Engine;

use SCS\Engine\Pairing\KeizerPairing;
use SCS\Engine\Pairing\ManualPairing;
use SCS\Engine\Pairing\PairingEngineInterface;
use SCS\Engine\Pairing\RoundRobinPairing;
use SCS\Engine\Settings\KeizerPairingSettings;
use SCS\Engine\Settings\RoundRobinSettings;
use SCS\Entity\Enum\PairingSystem;
use SCS\Entity\Season;
use SCS\Exception\ConflictException;

// Builds the pairing engine for a season from its pairing system.
final class PairingEngineResolver
{
    public function __construct(private readonly SettingsResolver $settings)
    {
    }

    public function resolve(Season $season): PairingEngineInterface
    {
        switch ($season->pairing_system) {
            case PairingSystem::Manual:
                return new ManualPairing();

            case PairingSystem::Keizer:
                $keizer = $this->settings->pairing($season);
                if (!$keizer instanceof KeizerPairingSettings) {
                    throw new ConflictException('This tournament has no Keizer settings to pair from.');
                }

                return new KeizerPairing($keizer);

            case PairingSystem::RoundRobinFull:
            case PairingSystem::RoundRobinGroups:
                $settings = $this->settings->pairing($season);
                if (!$settings instanceof RoundRobinSettings) {
                    throw new ConflictException('This tournament has no round-robin settings to pair from.');
                }

                return new RoundRobinPairing($settings);

            default:
                throw new ConflictException(sprintf(
                    'Automatic pairing is not available for %s yet — build the board by hand.',
                    $season->pairing_system->value
                ));
        }
    }
}
