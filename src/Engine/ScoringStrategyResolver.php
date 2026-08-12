<?php

declare(strict_types=1);

namespace SCS\Engine;

use SCS\Engine\Scoring\Keizer\ValueLadder;
use SCS\Engine\Scoring\KeizerScoring;
use SCS\Engine\Scoring\PlayerScoreCalculator;
use SCS\Engine\Scoring\ScoringStrategyInterface;
use SCS\Engine\Scoring\StandardScoring;
use SCS\Engine\Scoring\StandingsCalculator;
use SCS\Engine\Settings\KeizerScoringSettings;
use SCS\Engine\Settings\StandardScoringSettings;
use SCS\Entity\Season;

// Builds the scoring strategy for a season, configured with that season's stored settings.
final class ScoringStrategyResolver
{
    public function __construct(
        private readonly PlayerScoreCalculator $playerScores,
        private readonly StandingsCalculator $standings,
        private readonly ValueLadder $ladder,
        private readonly SettingsResolver $settings,
    ) {
    }

    /**
     * Dispatch is on the settings object rather than the season's system, so the
     * system → settings-class mapping lives only in SettingsResolver. Parsing the
     * blob here as well would let scoring read a season through one class while
     * SettingsValidator wrote it through another — a wrong standing, not an
     * error. No default arm: a settings class with no strategy should fail
     * loudly rather than score as standard.
     */
    public function resolve(Season $season): ScoringStrategyInterface
    {
        $settings = $this->settings->scoring($season);

        return match (true) {
            $settings instanceof KeizerScoringSettings => new KeizerScoring(
                $settings,
                $this->ladder,
                $this->playerScores,
                $this->standings,
            ),
            $settings instanceof StandardScoringSettings => new StandardScoring(
                $settings,
                $this->playerScores,
                $this->standings,
            ),
        };
    }
}
