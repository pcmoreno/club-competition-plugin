<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

/**
 * How hard the pairing works to satisfy colour and replay preferences.
 *
 * Standard walks the ranking and pairs neighbours, taking colours as they fall.
 * Colour aware will reach past a neighbour — up to the configured limit — when
 * doing so gives both players the colour they are owed. The weighted variants
 * are search problems that trade ladder position against colour or replay
 * quality by degrees, and are not built.
 */
enum PairingAlgorithm: string
{
    case Standard      = 'standard';
    case ColorAware    = 'color_aware';
    case WeightedStd   = 'weighted_standard';
    case WeightedColor = 'weighted_color';
    case WeightedReplay = 'weighted_replay';

    public function label(): string
    {
        return match ($this) {
            self::Standard       => 'Standard — follow the ranking',
            self::ColorAware     => 'Colour aware — look past a neighbour for a better colour',
            self::WeightedStd    => 'Weighted standard',
            self::WeightedColor  => 'Weighted colour',
            self::WeightedReplay => 'Weighted, avoiding rematches',
        };
    }

    public function isImplemented(): bool
    {
        return $this === self::Standard || $this === self::ColorAware;
    }

    // Only the reaching algorithms consult the limit; Standard never skips.
    public function usesLimit(): bool
    {
        return $this !== self::Standard;
    }
}
