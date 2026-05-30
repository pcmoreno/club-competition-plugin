<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

enum SeasonStatus: string
{
    case Preparation = 'preparation';
    case Active      = 'active';
    case Completed   = 'completed';
}
