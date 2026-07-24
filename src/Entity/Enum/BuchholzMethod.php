<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

// Buchholz variants (Sevilla parity). Only Classic is implemented; the rest are stubs returning 0.
enum BuchholzMethod: string
{
    case Classic       = 'classic';
    case EqualToScore  = 'equal_to_score';
    case Kallithea2009 = 'kallithea_2009';
    case Uscf          = 'uscf';
    case Fmjd          = 'fmjd';
    case Baku2023      = 'baku_2023';

    public function label(): string
    {
        return match ($this) {
            self::Classic       => 'Classic',
            self::EqualToScore  => 'Equal to score',
            self::Kallithea2009 => 'Kallithea 2009',
            self::Uscf          => 'USCF',
            self::Fmjd          => 'FMJD',
            self::Baku2023      => 'Baku 2023',
        };
    }

    public function isImplemented(): bool
    {
        return $this === self::Classic;
    }
}
