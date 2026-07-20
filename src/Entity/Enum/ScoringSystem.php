<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

enum ScoringSystem: string
{
    case Standard = 'standard';
    case Keizer   = 'keizer';
}
