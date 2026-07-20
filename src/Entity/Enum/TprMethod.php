<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

// Performance-rating method. Only the FIDE dp-table is implemented for now.
enum TprMethod: string
{
    case FideDp = 'fide_dp';

    public function label(): string
    {
        return match ($this) {
            self::FideDp => 'FIDE (dp table)',
        };
    }

    public function isImplemented(): bool
    {
        return $this === self::FideDp;
    }
}
