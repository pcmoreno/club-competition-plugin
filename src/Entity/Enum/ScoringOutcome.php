<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

// Intrinsic game results — always fixed. Byes are configurable data, not outcomes.
enum ScoringOutcome: string
{
    case Win  = 'win';
    case Draw = 'draw';
    case Loss = 'loss';

    public function label(): string
    {
        return match ($this) {
            self::Win  => 'Win',
            self::Draw => 'Draw',
            self::Loss => 'Loss',
        };
    }
}
