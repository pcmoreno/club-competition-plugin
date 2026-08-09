<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

/**
 * Which colour the top board's favoured player takes when colours alternate
 * down the sheet.
 *
 * Only meaningful alongside the alternating award — it sets which way the flip
 * starts. Sevilla offers to decide this itself; here that means white, which is
 * what an organiser expects on board one.
 */
enum FirstBoardColour: string
{
    case Automatic = 'automatic';
    case White     = 'white';
    case Black     = 'black';

    public function label(): string
    {
        return match ($this) {
            self::Automatic => 'Let the pairing decide',
            self::White     => 'White',
            self::Black     => 'Black',
        };
    }

    public function startsWhite(): bool
    {
        return $this !== self::Black;
    }
}
