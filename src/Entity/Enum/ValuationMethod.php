<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

/**
 * How a Keizer tournament turns a ranking into player values.
 *
 * Three are implemented. Position range spreads the field linearly between the
 * top and bottom values and is the one the club runs; the two stepped methods
 * walk a fixed step per rung from one end, reading the value at that end and
 * ignoring the other. The rest are Sevilla's remaining methods, carried so the
 * settings form shows the axis rather than pretending there is only one way to
 * do this — isImplemented() is what says which can be chosen.
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

    // The rating-derived methods need reference-score fields the settings form
    // has no way to reveal conditionally yet.
    public function isImplemented(): bool
    {
        return $this === self::PositionRange
            || $this === self::PositionFromTop
            || $this === self::PositionFromBottom;
    }

    // The stepped methods walk a fixed step per rung; the range method derives
    // its own from the spread and the size of the field.
    public function usesStep(): bool
    {
        return $this === self::PositionFromTop || $this === self::PositionFromBottom;
    }
}
