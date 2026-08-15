<?php

declare(strict_types=1);

namespace SCS\Engine;

use SCS\Engine\Pairing\KeizerPairing;
use SCS\Engine\Pairing\PairingEngineInterface;
use SCS\Engine\Pairing\RoundRobinPairing;
use SCS\Engine\Settings\KeizerPairingSettings;
use SCS\Engine\Settings\RoundRobinPairingSettings;
use SCS\Entity\Enum\PairingSystem;
use SCS\Entity\Season;
use SCS\Exception\ConflictException;

// Builds the pairing engine for a season from its pairing system.
final class PairingEngineResolver
{
    public function __construct(private readonly SettingsResolver $settings)
    {
    }

    /**
     * The engine that pairs this season, or a refusal.
     *
     * Which systems are refused isn't listed here — the enum's
     * generatesPairings() is asked, because that same answer is serialised as
     * `generates_pairings` and gates the Generate button. Two lists could
     * disagree, and did: manual once resolved to a do-nothing engine while the
     * enum said it pairs nothing, so a generate call ran the whole pairing path
     * — bye-row clear included — and reported success having built nothing.
     *
     * The default arm below is therefore unreachable, and says so by throwing a
     * LogicException: a system the enum claims pairs itself but that has no
     * engine is a coding error, not something to explain to an admin. The
     * reverse — an arm added without the enum — stays silent: the guard refuses
     * first and the arm is simply dead.
     */
    public function resolve(Season $season): PairingEngineInterface
    {
        if (!$season->pairing_system->generatesPairings()) {
            throw new ConflictException(sprintf(
                'Automatic pairing is not available for %s — build the board by hand.',
                $season->pairing_system->value
            ));
        }

        switch ($season->pairing_system) {
            case PairingSystem::Keizer:
                $keizer = $this->settings->pairing($season);
                if (!$keizer instanceof KeizerPairingSettings) {
                    throw new ConflictException('This tournament has no Keizer settings to pair from.');
                }

                return new KeizerPairing($keizer);

            case PairingSystem::RoundRobinFull:
                $settings = $this->settings->pairing($season);
                if (!$settings instanceof RoundRobinPairingSettings) {
                    throw new ConflictException('This tournament has no round-robin settings to pair from.');
                }

                return new RoundRobinPairing($settings);

            default:
                // Unreachable unless the enum gains a system that says it pairs
                // itself before this gains its engine. Not a ConflictException:
                // the admin did nothing wrong and no wording would help them.
                throw new \LogicException(sprintf(
                    'Pairing system "%s" reports that it generates pairings but has no engine.',
                    $season->pairing_system->value
                ));
        }
    }
}
