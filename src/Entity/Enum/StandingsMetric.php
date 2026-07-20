<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

// Computed scores — options for rankBy and tiebreakers.
enum StandingsMetric: string
{
    case Points           = 'points';
    case PerformanceRating = 'performance_rating';
    case SonnebornBerger  = 'sonneborn_berger';
    case Buchholz         = 'buchholz';
    case Wins             = 'wins';
    case DirectEncounter  = 'direct_encounter';

    public function label(): string
    {
        return match ($this) {
            self::Points            => 'Points',
            self::PerformanceRating => 'TPR',
            self::SonnebornBerger   => 'Sonneborn-Berger',
            self::Buchholz          => 'Buchholz',
            self::Wins              => 'Wins',
            self::DirectEncounter   => 'Direct encounter',
        };
    }
}
