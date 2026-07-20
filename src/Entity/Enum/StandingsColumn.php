<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

// Display vocabulary — the superset of columns the standings table can show (structural + computed).
enum StandingsColumn: string
{
    case Position            = 'position';
    case Name                = 'name';
    case Category            = 'category';
    case Rating              = 'rating';
    case StartRating         = 'start_rating';
    case Games               = 'games';
    case Wins                = 'wins';
    case Draws               = 'draws';
    case Losses              = 'losses';
    case Byes                = 'byes';
    case Points              = 'points';
    case SonnebornBerger     = 'sonneborn_berger';
    case Buchholz            = 'buchholz';
    case PerformanceRating   = 'performance_rating';
    case ColorBalance        = 'color_balance';
    case ColorStreak         = 'color_streak';
    case KeizerScore         = 'keizer_score';
    case KeizerScorePrevious = 'keizer_score_previous';
    case RankDelta           = 'rank_delta';

    public function label(): string
    {
        return match ($this) {
            self::Position            => 'Position',
            self::Name                => 'Name',
            self::Category            => 'Category',
            self::Rating              => 'Rating',
            self::StartRating         => 'Start rating',
            self::Games               => 'Games',
            self::Wins                => 'Wins',
            self::Draws               => 'Draws',
            self::Losses              => 'Losses',
            self::Byes                => 'Byes',
            self::Points              => 'Score',
            self::SonnebornBerger     => 'Sonneborn-Berger',
            self::Buchholz            => 'Buchholz',
            self::PerformanceRating   => 'TPR',
            self::ColorBalance        => 'Color balance',
            self::ColorStreak         => 'Color streak',
            self::KeizerScore         => 'Value',
            self::KeizerScorePrevious => 'Previous value',
            self::RankDelta           => 'Movement',
        };
    }
}
