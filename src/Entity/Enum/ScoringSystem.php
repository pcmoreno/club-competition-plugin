<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

enum ScoringSystem: string
{
    case Standard = 'standard';
    case Keizer   = 'keizer';

    // Both systems compute, so nothing is gated. This is the seam that refuses
    // a scoring system added before its strategy exists.
    public function isImplemented(): bool
    {
        return true;
    }
}
