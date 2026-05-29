<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

enum MemberStatus: string
{
    case Invited = 'invited';
    case Active  = 'active';
    case Revoked = 'revoked';
}
