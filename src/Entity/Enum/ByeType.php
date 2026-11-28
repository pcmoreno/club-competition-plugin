<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

// The bye keys the app writes itself, so every tournament must keep them; any other key is configured per tournament.
enum ByeType: string
{
    case Personal   = 'personal';
    case PairingBye = 'pairing_bye';
}
