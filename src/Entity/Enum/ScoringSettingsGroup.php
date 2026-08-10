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

    // The Keizer ladder, split the way Sevilla splits its own Score tab, so an
    // organiser reading across from it finds each knob where they expect it.
    case Calculation      = 'calculation';
    case PlayerValuation  = 'player_valuation';
    case Rounding         = 'rounding';
    case Aalsmeer         = 'aalsmeer';

    public function label(): string
    {
        return match ($this) {
            self::GameOutcomes     => 'Game outcomes',
            self::ByeTypes         => 'Bye types',
            self::RankBy           => 'Rank by',
            self::Tiebreakers      => 'Tie-breaks',
            self::Calculation      => 'Calculation',
            self::PlayerValuation  => 'Player valuation',
            self::Rounding         => 'Rounding',
            self::Aalsmeer         => 'Aalsmeer bonus',
        };
    }
}
