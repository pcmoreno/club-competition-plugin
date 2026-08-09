<?php

declare(strict_types=1);

namespace SCS\Engine;

use SCS\Engine\Settings\KeizerScoringSettings;
use SCS\Engine\Settings\ManualPairingSettings;
use SCS\Engine\Settings\RoundRobinGroupsPairingSettings;
use SCS\Engine\Settings\RoundRobinPairingSettings;
use SCS\Engine\Settings\Setting\NumberOfRounds;
use SCS\Engine\Settings\StandardScoringSettings;
use SCS\Engine\Settings\StandingsDisplaySettings;
use SCS\Engine\Settings\TournamentPairingSettings;
use SCS\Engine\Settings\TournamentScoringSettings;
use SCS\Engine\Settings\TournamentStandingsDisplaySettings;
use SCS\Entity\Enum\PairingSystem;
use SCS\Entity\Enum\ScoringSystem;
use SCS\Entity\Season;

// Hydrates a season's three stored settings blobs into their typed objects.
final class SettingsResolver
{
    // Null when the season's system has no pairing settings implemented yet.
    public function pairing(Season $season): ?TournamentPairingSettings
    {
        return $this->pairingFor($season->pairing_system, $season->pairing_settings ?? []);
    }

    /**
     * The same mapping keyed by system rather than season, so a submitted blob
     * can be normalised before any season holds it (see SettingsValidator).
     *
     * @param array<string,mixed> $values
     */
    public function pairingFor(PairingSystem $system, array $values): ?TournamentPairingSettings
    {
        return match ($system) {
            PairingSystem::Manual           => ManualPairingSettings::fromArray($values),
            PairingSystem::RoundRobinFull   => RoundRobinPairingSettings::fromArray($values),
            PairingSystem::RoundRobinGroups => RoundRobinGroupsPairingSettings::fromArray($values),
            default                         => null,
        };
    }

    // Every scoring system has settings — both of them are implemented.
    public function scoring(Season $season): TournamentScoringSettings
    {
        return $this->scoringFor($season->pairing_system, $season->scoring_settings ?? []);
    }

    /**
     * The same mapping keyed by system, so a submitted blob can be normalised
     * against the class that will actually read it — see SettingsValidator.
     *
     * @param array<string,mixed> $values
     */
    public function scoringFor(PairingSystem $system, array $values): TournamentScoringSettings
    {
        return match ($system->scoringSystem()) {
            ScoringSystem::Standard => StandardScoringSettings::fromArray($values),
            ScoringSystem::Keizer   => KeizerScoringSettings::fromArray($values),
        };
    }

    // Display is universal — every season has it.
    public function display(Season $season): TournamentStandingsDisplaySettings
    {
        return StandingsDisplaySettings::fromArray($season->display_settings ?? []);
    }

    /**
     * The last round number this season may have, or null for no limit.
     *
     * Read by key off the resolved pairing settings, so a system that doesn't
     * compose NumberOfRounds simply has no key and therefore no limit — which is
     * the right answer both for one that never caps rounds and for one that will
     * derive its own count (round-robin from the roster, a knockout from the
     * field size).
     */
    public function roundLimit(Season $season): ?int
    {
        $rounds = $this->pairing($season)?->getSettings()[NumberOfRounds::KEY] ?? null;

        return is_int($rounds) ? $rounds : null;
    }
}
