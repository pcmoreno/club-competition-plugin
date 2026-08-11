<?php

declare(strict_types=1);

namespace SCS\Engine\Settings;

use SCS\Engine\Settings\Setting\ByeTypes;
use SCS\Entity\Enum\BuchholzMethod;
use SCS\Entity\Enum\ScoringOutcome;
use SCS\Entity\Enum\StandingsMetric;
use SCS\Entity\Enum\TprMethod;

/**
 * The half of TournamentScoringSettings both scoring systems answer the same
 * way. Keizer multiplies these numbers by a player value where standard adds
 * them to a total, but reading them out of the stored blob is identical work,
 * and it was written out twice — long enough for the copy to lose the comment
 * on buchholzMethod() that stops the fallback being "improved" into one that
 * silently zeroes the metric.
 *
 * Requires of the using class: the readonly properties $gameOutcomes,
 * $byeTypes, $tiebreakers and $tiebreakConfig, and the constant
 * DEFAULT_BYE_TYPES. A trait rather than a base class because both classes hold
 * that state as promoted constructor properties and are built with named
 * arguments; inheriting would mean rewriting both constructors to gain nothing.
 * phpstan checks the property access per using class, so the requirement is
 * enforced rather than merely documented.
 *
 * The longer-term home for these is the knob each belongs to — byePoints on
 * ByeTypes, pointsFor on GameOutcomes, the tiebreak trio on TiebreakConfig —
 * which is the composition rule the settings classes already follow for schema
 * and coercion. That waits on those Setting objects carrying values, which they
 * don't today.
 */
trait SharedScoringSettings
{
    public function pointsFor(ScoringOutcome $outcome): float
    {
        return (float)($this->gameOutcomes[$outcome->value] ?? 0.0);
    }

    public function byePoints(string $key): float
    {
        foreach ($this->byeTypes as $bye) {
            if (($bye['key'] ?? null) === $key) {
                return (float)($bye['points'] ?? 0.0);
            }
        }

        return 0.0;
    }

    /** @return list<string> */
    public function reservedByeKeys(): array
    {
        return (new ByeTypes(self::DEFAULT_BYE_TYPES))->reservedKeys();
    }

    /** @return list<StandingsMetric> */
    public function tiebreakers(): array
    {
        return $this->tiebreakers;
    }

    public function directEncounterMaxGroup(): int
    {
        return (int)($this->tiebreakConfig['direct_encounter']['maxGroup'] ?? 2);
    }

    public function buchholzMethod(): BuchholzMethod
    {
        // Classic, not Baku2023: the fallback must name an implemented variant,
        // or an unparseable stored value silently disables the metric.
        return BuchholzMethod::tryFrom((string)($this->tiebreakConfig['buchholz']['method'] ?? '')) ?? BuchholzMethod::Classic;
    }

    public function tprMethod(): TprMethod
    {
        return TprMethod::tryFrom((string)($this->tiebreakConfig['performance_rating']['method'] ?? '')) ?? TprMethod::FideDp;
    }
}
