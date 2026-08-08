<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

enum AdminStatus: string
{
    // Invited: created from the Admins tab, emailed a link, no password yet.
    // An invited admin can't sign in — AuthContextService requires Active.
    case Invited = 'invited';
    case Active  = 'active';
    case Revoked = 'revoked';
}
