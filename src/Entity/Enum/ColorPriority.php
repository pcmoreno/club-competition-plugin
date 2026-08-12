<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

/**
 * Who wins when both players are owed the same colour.
 *
 * Someone has to be disappointed, so the question is only whether that is
 * decided by standing, by rating, or not at all. Higher ranked is Sevilla's
 * default and the one a club can explain: the player having the better season
 * gets the colour they are due.
 */
enum ColorPriority: string
{
    case HigherRanked = 'higher_ranked';
    case HigherRated  = 'higher_rated';
    case None         = 'none';

    public function label(): string
    {
        return match ($this) {
            self::HigherRanked => 'The higher ranked player',
            self::HigherRated  => 'The higher rated player',
            self::None         => 'Neither — take them as they fall',
        };
    }
}
