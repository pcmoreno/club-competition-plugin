<?php

declare(strict_types=1);

namespace SCS\Engine\Scoring;

use SCS\Entity\Enum\StandingsMetric;

// A player's full computed score set after Pass 1 — an open metric map plus the structural counts.
final class PlayerScore
{
    /** @param array<string,float|int|null> $scores metric value => score */
    public function __construct(
        public readonly int $seasonPlayerId,
        public readonly array $scores,
    ) {
    }

    public function metric(StandingsMetric $metric): float
    {
        return (float)($this->scores[$metric->value] ?? 0.0);
    }

    public function count(string $key): int
    {
        return (int)($this->scores[$key] ?? 0);
    }
}
