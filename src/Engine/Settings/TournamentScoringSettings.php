<?php

declare(strict_types=1);

namespace SCS\Engine\Settings;

use SCS\Entity\Enum\BuchholzMethod;
use SCS\Entity\Enum\ScoringOutcome;
use SCS\Entity\Enum\StandingsMetric;
use SCS\Entity\Enum\TprMethod;

/**
 * The contract every scoring system's settings answer, not just a marker.
 *
 * Keizer and standard scoring price the same events differently but ask the
 * same questions of their settings, so the metric calculators and the ranking
 * pass depend on this rather than on one system's class.
 */
interface TournamentScoringSettings extends SettingsInterface
{
    public function pointsFor(ScoringOutcome $outcome): float;

    public function byePoints(string $key): float;

    /**
     * Bye types the engine assigns itself, which a client payload may never drop.
     *
     * @return list<string>
     */
    public function reservedByeKeys(): array;

    public function rankBy(): StandingsMetric;

    /** @return list<StandingsMetric> ordered */
    public function tiebreakers(): array;

    public function directEncounterMaxGroup(): int;

    public function buchholzMethod(): BuchholzMethod;

    public function tprMethod(): TprMethod;
}
