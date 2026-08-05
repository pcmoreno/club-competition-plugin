<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

/**
 * How a round-robin assigns pairing numbers.
 *
 * A Berger table is fully determined by those numbers, so this is the only free
 * input the schedule has — everything else about the fixture follows from it.
 */
enum SeedingMethod: string
{
    case Rating    = 'rating';
    case Lot       = 'lot';
    case Enrolment = 'enrolment';

    public function label(): string
    {
        return match ($this) {
            self::Rating    => 'Rating, highest first',
            self::Lot       => 'Drawing of lots',
            self::Enrolment => 'Enrolment order',
        };
    }
}
