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
 * Random is Sevilla's default for ladder systems, and the club's. The draw is
 * taken from the round rather than the clock, so it stays arbitrary between
 * rounds while giving the same answer every time a given round is generated —
 * regenerating a draft board can't quietly move the bye onto someone else.
 *
 * The unimplemented entries are not really alternative *choices*: three of them
 * are different mechanics, where the bye falls out of the pairing rather than
 * being picked beforehand, one wants an admin to name the player, and a pairing
 * number is a round-robin idea with no meaning in a ladder.
 */
enum PairingByeChoice: string
{
    case Random             = 'random';
    case LowestRanked       = 'lowest_ranked';
    case DuringPairing      = 'during_pairing';
    case Remainder          = 'remainder';
    case LowestRankedColour = 'lowest_ranked_with_color';
    case Manual             = 'manual';
    case LowestRating       = 'lowest_rating';
    case PairingNumber      = 'pairing_number';

    public function label(): string
    {
        return match ($this) {
            self::Random             => 'At random',
            self::LowestRanked       => 'Lowest ranked of those eligible',
            self::DuringPairing      => 'Whoever is left as the pairing runs',
            self::Remainder          => 'The remainder after pairing',
            self::LowestRankedColour => 'Lowest ranked, taking colour into account',
            self::Manual             => 'Chosen by the organiser',
            self::LowestRating       => 'Lowest rated of those eligible',
            self::PairingNumber      => 'By pairing number',
        };
    }

    public function isImplemented(): bool
    {
        return $this === self::Random || $this === self::LowestRanked;
    }
}
