<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

/**
 * How a Keizer tournament turns a ranking into player values.
 *
 * Only Position range is implemented, and it is the one the club runs: the top
 * of the ranking takes the top value, the bottom takes the bottom value, and
 * everyone between is spread linearly. The rest are Sevilla's other valuation
 * methods, carried so the settings form shows the axis rather than pretending
 * there is only one way to do this.
 */
enum ValuationMethod: string
{
    case PositionRange    = 'position_range';
    case PositionFromTop  = 'position_from_top';
    case PositionFromBottom = 'position_from_bottom';
    case Strength         = 'strength';
    case Absolute         = 'absolute';
    case Expectation      = 'expectation';
    case ValueGroups      = 'value_groups';

    public function label(): string
    {
        return match ($this) {
            self::PositionRange       => 'Position range, top to bottom',
            self::PositionFromTop     => 'Position from top, by a fixed step',
            self::PositionFromBottom  => 'Position from bottom, by a fixed step',
            self::Strength            => 'Relative strength',
            self::Absolute            => 'The valuation criterion itself',
            self::Expectation         => 'Expected score',
            self::ValueGroups         => 'Bands between two reference scores',
        };
    }

    // The stepped and rating-derived methods need value step / reference score
    // fields the settings form has no way to reveal conditionally yet.
    public function isImplemented(): bool
    {
        return $this === self::PositionRange;
    }
}
