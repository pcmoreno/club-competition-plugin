<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

enum PairingSystem: string
{
    case Keizer            = 'keizer';
    case Swiss             = 'swiss';
    case RoundRobinFull   = 'round-robin-full';
    case RoundRobinGroups = 'round-robin-groups';
}
