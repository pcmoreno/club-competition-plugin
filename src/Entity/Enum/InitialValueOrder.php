<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

/**
 * How the value ladder is ordered in round 1, before any score exists.
 *
 * Sevilla calls this "initialize values on" and offers player index or a
 * rating. Enrolment order is our player index; there is deliberately no random
 * option, because an arbitrary opening ladder is not something a member can be
 * told the reason for, and every later round orders on score anyway.
 */
enum InitialValueOrder: string
{
    case Rating    = 'rating';
    case Enrolment = 'enrolment';

    public function label(): string
    {
        return match ($this) {
            self::Rating    => 'Rating, highest first',
            self::Enrolment => 'Enrolment order',
        };
    }
}
