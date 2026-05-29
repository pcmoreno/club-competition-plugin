<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

enum AdminStatus: string
{
    case Active  = 'active';
    case Revoked = 'revoked';
}
