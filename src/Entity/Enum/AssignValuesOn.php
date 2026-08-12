<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

/**
 * Which ranking a player's value is read from.
 *
 * Score is the ordinary Keizer answer and the club's: your value follows where
 * you stand. The alternatives value players by strength instead — a rating or a
 * performance — which stops a regular who plays every week from outranking a
 * stronger player who turns up half as often, at the cost of the ladder no
 * longer reflecting the competition itself.
 */
enum AssignValuesOn: string
{
    case Score             = 'score';
    case SevillaPercentage = 'sevilla_percentage';
    case Tpr               = 'tpr';
    case Rating            = 'rating';
    case InitialRating     = 'initial_rating';
    case Position          = 'position';

    public function label(): string
    {
        return match ($this) {
            self::Score             => 'The Keizer score',
            self::SevillaPercentage => 'The damped score percentage',
            self::Tpr               => 'Tournament performance',
            self::Rating            => 'Current rating',
            self::InitialRating     => 'Starting rating',
            self::Position          => 'Position on the ranking',
        };
    }

    public function isImplemented(): bool
    {
        return $this === self::Score;
    }
}
