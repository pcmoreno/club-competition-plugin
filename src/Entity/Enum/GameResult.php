<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

enum GameResult: string
{
    case White = 'white';
    case Black = 'black';
    case Draw  = 'draw';
}
