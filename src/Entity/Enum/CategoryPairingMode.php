<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

/**
 * Whether categories limit who may be paired against whom.
 *
 * Free lets the ranking decide alone. Adjacent keeps players within a set
 * number of categories of their own — at one, the club's arrangement, an A may
 * meet an A or a B but never a C, however the standings happen to fall.
 *
 * Worth knowing why this can't be left to the ranking: over the club's own
 * season an A player sat directly next to a C player in the standings 378
 * times, and an A-versus-B board was made 24 rating positions apart. Nothing
 * about proximity or width stops an A meeting a C — only the category does.
 */
enum CategoryPairingMode: string
{
    case Free     = 'free';
    case Adjacent = 'adjacent';

    public function label(): string
    {
        return match ($this) {
            self::Free     => 'No limit — any two players may meet',
            self::Adjacent => 'Within a set number of categories',
        };
    }
}
