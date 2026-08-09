<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

/**
 * What the player favoured by the colour tiebreak actually receives.
 *
 * Alternate is the useful one: rather than handing the same colour to every
 * favoured player, it flips down the boards, so an opening round comes out with
 * white spread evenly across the sheet instead of concentrated among whoever
 * happened to win the tiebreak.
 */
enum ColorTieAward: string
{
    case Alternate = 'alternate';
    case White     = 'white';
    case Black     = 'black';

    public function label(): string
    {
        return match ($this) {
            self::Alternate => 'Alternating colours down the boards',
            self::White     => 'White',
            self::Black     => 'Black',
        };
    }
}
