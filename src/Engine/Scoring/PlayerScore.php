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

    // Every caller sorts. An unknown metric (a TPR with no rated opponent)
    // orders as 0.0 by decision, sharing a rank with a genuine 0.0; display
    // reads $scores directly and still renders it as "—".
    public function metric(StandingsMetric $metric): float
    {
        return (float)($this->scores[$metric->value] ?? 0.0);
    }

    public function count(string $key): int
    {
        return (int)($this->scores[$key] ?? 0);
    }
}
