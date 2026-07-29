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

    // Direct encounter is tiebreak-only: it compares a tied group against itself,
    // so it has no value to rank a whole field by. Used as rankBy it is filtered
    // out of the rank key, leaving an empty key that puts every player on rank 1.
    public function canRankBy(): bool
    {
        return $this !== self::DirectEncounter;
    }
}
