<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

// The groups a scoring settings form is organised into.
enum ScoringSettingsGroup: string
{
    case GameOutcomes = 'game_outcomes';
    case ByeTypes     = 'bye_types';
    case RankBy       = 'rank_by';
    case Tiebreakers  = 'tiebreakers';
}
