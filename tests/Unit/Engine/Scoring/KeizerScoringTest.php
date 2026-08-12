<?php

declare(strict_types=1);

namespace SCS\Tests\Unit\Engine\Scoring;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SCS\Engine\Scoring\Keizer\ValueLadder;
use SCS\Engine\Scoring\KeizerScoring;
use SCS\Engine\Scoring\Metric\BuchholzCalculator;
use SCS\Engine\Scoring\Metric\PerformanceRatingCalculator;
use SCS\Engine\Scoring\Metric\PointsCalculator;
use SCS\Engine\Scoring\Metric\SonnebornBergerCalculator;
use SCS\Engine\Scoring\Metric\WinsCalculator;
use SCS\Engine\Scoring\PlayerScoreCalculator;
use SCS\Engine\Scoring\StandingsCalculator;
use SCS\Engine\Settings\KeizerScoringSettings;
use SCS\Entity\StandingsSnapshot;
use SCS\Tests\Unit\Engine\Scoring\Fixture\SeasonFixture;

/**
 * The Keizer engine against the club's own 2025-26 season.
 *
 * The fixture carries the standings that were actually published after every
 * round, so these are not tests of internal consistency — they check that the
 * engine computes the numbers members were shown. Nothing else can catch a
 * scoring drift, because a wrong Keizer score is still a plausible-looking one.
 */
final class KeizerScoringTest extends TestCase
{
    private SeasonFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = SeasonFixture::load('competition_2025_2026');
    }

    /**
     * Round one, against the scores the club published.
     *
     * Scores land within a point or two rather than exactly, and the reason is
     * known: the ladder is a straight ramp from 200 to 100 across the enrolled
     * field, so a player sitting one rung out is worth about 1.7 points more or
     * less than they should be, which carries into their opponent's score too.
     * Reconstructing Sevilla's exact round-one order is not possible — the
     * competition file is gone, and no combination of top value and step does
     * better than this against the order we can rebuild from ratings (searched
     * top 198-203, step 1.6-1.9).
     *
     * The tolerance is therefore deliberate, and it is still a real test: a
     * genuine scoring fault — a mispriced bye, a missing own-value, an opponent
     * value read from the wrong round — moves scores by tens or hundreds, not
     * by two.
     */
    #[Test]
    public function it_reproduces_round_one_within_a_rung_of_the_published_scores(): void
    {
        $expected = $this->fixture->standings(1);
        $actual   = $this->indexById($this->computeRound(1, []));

        self::assertSame(
            count($expected),
            count($actual),
            'Round one should rank exactly the players who appeared in it.'
        );

        $total = 0.0;
        foreach ($expected as $seasonPlayerId => $row) {
            self::assertArrayHasKey($seasonPlayerId, $actual, "Missing {$row['name']} from the standings.");

            $drift = abs($actual[$seasonPlayerId]->keizer_score - (int)$row['keizer_score']);
            $total += $drift;

            self::assertLessThanOrEqual(
                2,
                $drift,
                sprintf('%s scored %d against a published %d', $row['name'], $actual[$seasonPlayerId]->keizer_score, $row['keizer_score'])
            );
        }

        self::assertLessThan(
            1.0,
            $total / count($expected),
            'Average drift across the field should stay under a single point.'
        );
    }

    #[Test]
    public function it_puts_the_published_leader_at_the_top(): void
    {
        $snapshots = $this->computeRound(1, []);
        $expected  = $this->fixture->standings(1);

        $leader = $snapshots[0];
        self::assertSame(1, $leader->rank);
        self::assertSame(1, (int)$expected[$leader->season_player_id]['rank']);
    }

    #[Test]
    public function it_ranks_only_players_who_have_appeared(): void
    {
        $snapshots = $this->computeRound(1, []);

        self::assertLessThan(
            count($this->fixture->roster),
            count($snapshots),
            'Enrolled players who have not played yet must not appear in the standings.'
        );
    }

    #[Test]
    public function it_refuses_a_round_whose_predecessor_has_no_standings(): void
    {
        $this->expectExceptionMessageMatches('/scores against the standings of the round before it/');

        $this->computeRound(2, []);
    }

    /**
     * Score, then rank, exactly as RoundService drives it.
     *
     * @param  list<StandingsSnapshot> $previous
     * @return list<StandingsSnapshot>
     */
    private function computeRound(int $number, array $previous): array
    {
        $games      = [];
        $attendance = [];
        foreach ($this->fixture->roundNumbers() as $earlier) {
            if ($earlier > $number) {
                continue;
            }
            $games      = array_merge($games, $this->fixture->games($earlier));
            $attendance = array_merge($attendance, $this->fixture->attendance($earlier));
        }

        return $this->engine()->computeStandings(
            $this->fixture->season(),
            $this->fixture->round($number),
            $this->fixture->roster,
            $games,
            $attendance,
            $previous,
        );
    }

    private function engine(): KeizerScoring
    {
        return new KeizerScoring(
            new KeizerScoringSettings(),
            new ValueLadder(),
            new PlayerScoreCalculator([
                new PointsCalculator(),
                new WinsCalculator(),
                new SonnebornBergerCalculator(),
                new BuchholzCalculator(),
                new PerformanceRatingCalculator(),
            ]),
            new StandingsCalculator(),
        );
    }

    /**
     * @param  list<StandingsSnapshot>      $snapshots
     * @return array<int,StandingsSnapshot>
     */
    private function indexById(array $snapshots): array
    {
        $byId = [];
        foreach ($snapshots as $snapshot) {
            $byId[$snapshot->season_player_id] = $snapshot;
        }

        return $byId;
    }
}
