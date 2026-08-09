<?php

declare(strict_types=1);

namespace SCS\Engine\Scoring;

use SCS\Entity\Round;
use SCS\Entity\Season;

// Every format scores; the strategy is resolved from the season's derived scoring system.
interface ScoringStrategyInterface
{
    /**
     * @param list<\SCS\Entity\SeasonPlayer>      $roster
     * @param list<\SCS\Entity\Game>              $games              all completed games through this round
     * @param list<\SCS\Entity\Attendance>        $attendance         all attendance through this round
     * @param list<\SCS\Entity\StandingsSnapshot> $previousStandings  the round before this one, where it exists
     * @return list<\SCS\Entity\StandingsSnapshot>
     */
    public function computeStandings(
        Season $season,
        Round $round,
        array $roster,
        array $games,
        array $attendance,
        array $previousStandings = [],
    ): array;
}
