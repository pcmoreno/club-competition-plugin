<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

enum ScoringSystem: string
{
    case Standard = 'standard';
    case Keizer   = 'keizer';

    // Keizer has no strategy yet, so ScoringStrategyResolver can't build one and
    // a round using it cannot be completed.
    public function isImplemented(): bool
    {
        return $this === self::Standard;
    }
}
