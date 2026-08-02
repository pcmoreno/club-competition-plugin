<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

/**
 * The tempo a game was played at. The club's internal competition is classical,
 * so that's the default; blitz and rapid cover the side tournaments a season can
 * also model.
 */
enum TimeControl: string
{
    case Blitz     = 'blitz';
    case Rapid     = 'rapid';
    case Classical = 'classical';
}
