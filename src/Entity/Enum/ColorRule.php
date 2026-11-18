<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

/**
 * Sevilla's "mutual colour preference": how colours go between two players who
 * have met before.
 *
 * Alternating swaps the colours of their last game together. Balance to zero
 * keeps their games against each other within one of even, and leaves an even
 * pair to the ordinary rules. Balance/Alternate does that, then alternates an
 * even pair. No extra rules treats a rematch like any other board.
 */
enum ColorRule: string
{
    case Alternating      = 'alternating';
    case BalanceToZero    = 'balance_to_zero';
    case BalanceAlternate  = 'balance_alternate';
    case NoExtraRules     = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Alternating      => 'Alternate from their last game together',
            self::BalanceToZero    => 'Even out their games against each other',
            self::BalanceAlternate => 'Even out, then alternate',
            self::NoExtraRules     => 'No extra rule',
        };
    }
}
