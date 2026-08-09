<?php

declare(strict_types=1);

namespace SCS\Entity\Enum;

/**
 * How far the recalculation of values is carried each round.
 *
 * Classic is one pass: work out everyone's value from last round's ranking,
 * then rank the season again with those values, and stop. The heavier modes
 * keep going until the values stop moving, which converges on a more defensible
 * ranking but makes it much harder to explain why a score changed — and neither
 * is what the club runs.
 */
enum RevaluationMode: string
{
    case Classic  = 'classic';
    case None     = 'none';
    case Extended = 'extended';
    case Complete = 'complete';

    public function label(): string
    {
        return match ($this) {
            self::Classic  => 'Once per round',
            self::None     => 'No revaluation of earlier rounds',
            self::Extended => 'Repeat the latest round until values settle',
            self::Complete => 'Repeat every round until values settle',
        };
    }

    public function isImplemented(): bool
    {
        return $this === self::Classic;
    }
}
