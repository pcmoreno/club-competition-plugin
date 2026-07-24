<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

enum ByeType: string
{
    case Personal   = 'personal';
    case ClubDuty   = 'club_duty';
    case PairingBye = 'pairing_bye';
}
