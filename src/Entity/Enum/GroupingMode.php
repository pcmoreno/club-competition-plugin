<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

// How a grouped round-robin splits its field into sections.
enum GroupingMode: string
{
    case Categories = 'categories';
    case FixedCount = 'fixed_count';
    case FixedSize  = 'fixed_size';

    public function label(): string
    {
        return match ($this) {
            self::Categories => 'The tournament’s categories',
            self::FixedCount => 'A fixed number of groups, seeded by rating',
            self::FixedSize  => 'Groups of a fixed size, seeded by rating',
        };
    }

    // The rating splits need a group count/size field alongside them, which the
    // settings form can't reveal conditionally yet.
    public function isImplemented(): bool
    {
        return $this === self::Categories;
    }
}
