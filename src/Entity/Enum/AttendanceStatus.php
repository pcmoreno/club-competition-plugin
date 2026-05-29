<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent  = 'absent';
}
