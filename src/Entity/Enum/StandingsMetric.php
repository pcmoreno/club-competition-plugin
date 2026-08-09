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
    case KeizerScore      = 'keizer_score';

    public function label(): string
    {
        return match ($this) {
            self::Points            => 'Points',
            self::PerformanceRating => 'TPR',
            self::SonnebornBerger   => 'Sonneborn-Berger',
            self::Buchholz          => 'Buchholz',
            self::Wins              => 'Wins',
            self::DirectEncounter   => 'Direct encounter',
            self::KeizerScore       => 'Value',
        };
    }

    // The Keizer score isn't offered as a choice anywhere: it's what a Keizer
    // season ranks by unconditionally, and it reads zero in any other system.
    public function isSelectable(): bool
    {
        return $this !== self::KeizerScore;
    }

    // Direct encounter is tiebreak-only: it compares a tied group against itself,
    // so it has no value to rank a whole field by. Used as rankBy it is filtered
    // out of the rank key, leaving an empty key that puts every player on rank 1.
    public function canRankBy(): bool
    {
        return $this !== self::DirectEncounter;
    }
}
