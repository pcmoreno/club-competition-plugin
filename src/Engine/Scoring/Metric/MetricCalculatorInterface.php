<?php

declare(strict_types=1);

namespace SCS\Engine\Scoring\Metric;

use SCS\Engine\Scoring\ScoringContext;
use SCS\Engine\Settings\TournamentScoringSettings;
use SCS\Entity\Enum\StandingsMetric;

// One metric, one column. Pure — reads the context, returns season_player_id => value.
interface MetricCalculatorInterface
{
    public function metric(): StandingsMetric;

    public function isImplemented(TournamentScoringSettings $settings): bool;

    /** @return array<int,float|null> null = the metric doesn't apply to that player */
    public function calculate(ScoringContext $context): array;
}
