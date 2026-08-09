<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

/**
 * Who sits out when an odd number of players turn up.
 *
 * Whichever rule applies, nobody takes a second pairing bye until everyone has
 * had one — that part isn't configurable, and it's what stops the same regular
 * losing an evening twice while others never do.
 *
 * Random is Sevilla's default for ladder systems. Drawing it from the round
 * rather than the clock keeps it arbitrary between rounds while staying the
 * same every time a given round is generated, so regenerating a draft board
 * doesn't quietly move the bye onto someone else.
 */
enum PairingByeChoice: string
{
    case Random       = 'random';
    case LowestRanked = 'lowest_ranked';

    public function label(): string
    {
        return match ($this) {
            self::Random       => 'At random',
            self::LowestRanked => 'Lowest ranked of those eligible',
        };
    }
}
