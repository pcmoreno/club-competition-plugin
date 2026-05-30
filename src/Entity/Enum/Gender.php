<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

enum Gender: string
{
    case Male   = 'male';
    case Female = 'female';
    case Other  = 'other';
}
