<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

enum RoundStatus: string
{
    case Draft     = 'draft';
    case Published = 'published';
    case Finalised = 'finalised';
    case Complete  = 'complete';
}
