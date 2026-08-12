<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

/**
 * Who is favoured when nothing else has decided a board's colours.
 *
 * This only fires when neither player has any claim at all — the opening round,
 * or two players who have both just come back from an absence. After that the
 * colour history settles it long before this does.
 *
 * "Pairing number" is Sevilla's stable index for a player. A ladder has no
 * pairing numbers of its own, so it reads here as enrolment order, which is the
 * same idea: the order the field was written down in, unchanged by results.
 */
enum ColorTieCriterion: string
{
    case LowerPairingNumber = 'lower_pairing_number';
    case HigherRanked       = 'higher_ranked';
    case HigherRated        = 'higher_rated';
    case Random             = 'random';
    case LowerPlayerNumber  = 'lower_player_number';
    case HigherAor          = 'higher_aor';

    public function label(): string
    {
        return match ($this) {
            self::LowerPairingNumber => 'Whoever enrolled first',
            self::HigherRanked       => 'The higher ranked player',
            self::HigherRated        => 'The higher rated player',
            self::Random             => 'At random',
            self::LowerPlayerNumber  => 'Lower player number',
            self::HigherAor          => 'Higher average opponent rating',
        };
    }

    // A player number distinct from enrolment order, and an average-opponent
    // rating, are both things we don't keep.
    public function isImplemented(): bool
    {
        return $this !== self::LowerPlayerNumber && $this !== self::HigherAor;
    }
}
