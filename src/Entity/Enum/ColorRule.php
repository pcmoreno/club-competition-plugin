<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

/**
 * What a player is owed when colours are decided.
 *
 * Alternating reads only the last game: you had white, so you want black.
 * Balance to zero reads the whole season and gives the colour that pulls a
 * player's running difference back towards even — a different answer whenever
 * someone's last game contradicts their overall balance.
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
            self::Alternating      => 'Alternate from the last game',
            self::BalanceToZero    => 'Even out the season’s colours',
            self::BalanceAlternate => 'Even out, then alternate',
            self::NoExtraRules     => 'No colour rule',
        };
    }

    public function isImplemented(): bool
    {
        return $this === self::Alternating || $this === self::BalanceToZero;
    }
}
