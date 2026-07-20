<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

enum PairingSystem: string
{
    case Manual            = 'manual';
    case Keizer            = 'keizer';
    case Swiss             = 'swiss';
    case RoundRobinFull   = 'round-robin-full';
    case RoundRobinGroups = 'round-robin-groups';

    // Scoring is derived from the pairing system (code-only map; only Keizer scores its own way).
    public function scoringSystem(): ScoringSystem
    {
        return match ($this) {
            self::Keizer => ScoringSystem::Keizer,
            default      => ScoringSystem::Standard,
        };
    }
}
