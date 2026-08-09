<?php

declare(strict_types=1);

namespace SCS\Tests\Unit\Engine\Pairing;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SCS\Engine\Pairing\KeizerPairing;
use SCS\Engine\Settings\KeizerPairingSettings;
use SCS\Entity\Enum\CategoryPairingMode;
use SCS\Entity\Enum\GameResult;
use SCS\Entity\Enum\PairingSystem;
use SCS\Entity\Enum\SeasonStatus;
use SCS\Entity\Game;
use SCS\Entity\Season;
use SCS\Entity\SeasonPlayer;
use SCS\Entity\StandingsSnapshot;

/**
 * The properties a Keizer board has to have, whatever the ranking looks like.
 *
 * Exact board-for-board reproduction isn't checkable — the club's own pairings
 * came out of an ordering we can't rebuild — so these pin what must hold every
 * time instead: everyone present gets a game, nobody plays twice, the odd
 * player out is spread around, and colours alternate.
 */
final class KeizerPairingTest extends TestCase
{
    #[Test]
    public function it_pairs_every_player_exactly_once(): void
    {
        $roster = $this->roster(12);
        $result = $this->pair($roster, [], $this->standings($roster));

        self::assertCount(6, $result->pairings);
        self::assertSame([], $result->byes);
        self::assertSame(range(1, 6), array_column($result->pairings, 'board'));

        $seen = [];
        foreach ($result->pairings as $pairing) {
            $seen[] = $pairing['white'];
            $seen[] = $pairing['black'];
        }
        sort($seen);
        self::assertSame(range(1, 12), $seen, 'Every player should appear on exactly one board.');
    }

    #[Test]
    public function it_gives_the_odd_player_a_pairing_bye(): void
    {
        $roster = $this->roster(11);
        $result = $this->pair($roster, [], $this->standings($roster));

        self::assertCount(5, $result->pairings);
        self::assertCount(1, $result->byes);
        self::assertSame('pairing_bye', $result->byes[0]['bye_type']);

        $paired = [];
        foreach ($result->pairings as $pairing) {
            $paired[] = $pairing['white'];
            $paired[] = $pairing['black'];
        }
        self::assertNotContains($result->byes[0]['season_player_id'], $paired, 'The player with the bye must not also have a game.');
    }

    #[Test]
    public function it_does_not_give_a_second_bye_while_anyone_is_still_waiting(): void
    {
        $roster = $this->roster(11);

        // Everyone but the last player has already sat out once.
        $standings = [];
        foreach ($roster as $index => $player) {
            $standings[] = $this->snapshot($player->id, $index + 1, byes: $index < 10 ? 1 : 0);
        }

        $result = $this->pair($roster, [], $standings);

        self::assertSame(11, $result->byes[0]['season_player_id'], 'The bye should go to the only player who has not had one.');
    }

    /**
     * Colours alternate — tested with the rematch window switched off.
     *
     * With the window on, as it is by default, the engine refuses to pair 1
     * against 2 a second time, so the board changes and there is nothing left
     * to say about whose turn it is for white. The window has its own tests.
     */
    #[Test]
    public function it_alternates_colours_from_the_previous_game(): void
    {
        $roster = $this->roster(4);

        // Everyone played last round; 1 and 3 had white, 2 and 4 had black.
        $history = [
            $this->game(1, 1, 2),
            $this->game(2, 3, 4),
        ];

        $result = $this->pair(
            $roster,
            $history,
            $this->standings($roster),
            new KeizerPairingSettings(rematchWindow: 0),
        );

        foreach ($result->pairings as $pairing) {
            self::assertContains($pairing['white'], [2, 4], 'A player who had black should be given white.');
            self::assertContains($pairing['black'], [1, 3], 'A player who had white should be given black.');
        }
    }

    #[Test]
    public function it_avoids_repeating_a_pairing_inside_the_window(): void
    {
        $result = $this->pair(
            $this->roster(4),
            [$this->game(1, 1, 2), $this->game(2, 3, 4)],
            $this->standings($this->roster(4)),
        );

        $met = $this->pairsMade($result);

        self::assertNotContains('1v2', $met, 'These two met last round and the window has not passed.');
        self::assertNotContains('3v4', $met, 'These two met last round and the window has not passed.');
    }

    #[Test]
    public function it_repeats_a_pairing_once_the_window_has_passed(): void
    {
        // A one-round window is satisfied by the round just played, so the
        // ranking gets its way again and neighbours meet.
        $result = $this->pair(
            $this->roster(4),
            [$this->game(1, 1, 2), $this->game(2, 3, 4)],
            $this->standings($this->roster(4)),
            new KeizerPairingSettings(rematchWindow: 1),
        );

        $met = $this->pairsMade($result);

        self::assertContains('1v2', $met);
        self::assertContains('3v4', $met);
    }

    #[Test]
    public function it_never_pairs_across_more_categories_than_allowed(): void
    {
        // Three categories interleaved through the ranking, so pairing on
        // proximity alone would put an A against a C constantly.
        $roster = [];
        $id     = 0;
        foreach (['A', 'B', 'C', 'A', 'B', 'C', 'A', 'B', 'C', 'A', 'B', 'C'] as $category) {
            $id++;
            $roster[] = new SeasonPlayer(
                id:          $id,
                season_id:   1,
                player_id:   $id,
                category:    $category,
                elo_rating:  2000 - $id * 25,
                enrolled_at: new \DateTimeImmutable('2026-09-01'),
            );
        }

        $result = $this->pair($roster, [], $this->standings($roster), null, ['A', 'B', 'C']);

        $position = ['A' => 0, 'B' => 1, 'C' => 2];
        $of       = [];
        foreach ($roster as $player) {
            $of[$player->id] = $position[$player->category];
        }

        self::assertCount(6, $result->pairings);
        foreach ($result->pairings as $pairing) {
            self::assertLessThanOrEqual(
                1,
                abs($of[$pairing['white']] - $of[$pairing['black']]),
                'An A must never be paired against a C.'
            );
        }
    }

    #[Test]
    public function it_ignores_categories_when_the_limit_is_off(): void
    {
        $roster = [
            $this->categorised(1, 'A', 2100),
            $this->categorised(2, 'C', 1200),
        ];

        $result = $this->pair(
            $roster,
            [],
            $this->standings($roster),
            new KeizerPairingSettings(categoryPairing: CategoryPairingMode::Free),
            ['A', 'B', 'C'],
        );

        self::assertCount(1, $result->pairings, 'With no limit the two must still get a game.');
    }

    private function categorised(int $id, string $category, int $rating): SeasonPlayer
    {
        return new SeasonPlayer(
            id:          $id,
            season_id:   1,
            player_id:   $id,
            category:    $category,
            elo_rating:  $rating,
            enrolled_at: new \DateTimeImmutable('2026-09-01'),
        );
    }

    /** @return list<string> */
    private function pairsMade(\SCS\Engine\Pairing\PairingResult $result): array
    {
        $met = [];
        foreach ($result->pairings as $pairing) {
            $pair = [$pairing['white'], $pairing['black']];
            sort($pair);
            $met[] = implode('v', $pair);
        }

        return $met;
    }

    #[Test]
    public function the_bye_is_stable_when_the_same_round_is_paired_again(): void
    {
        $roster    = $this->roster(9);
        $standings = $this->standings($roster);

        $first  = $this->pair($roster, [], $standings);
        $second = $this->pair($roster, [], $standings);

        self::assertSame(
            $first->byes[0]['season_player_id'],
            $second->byes[0]['season_player_id'],
            'Regenerating a draft round must not move the bye onto someone else.'
        );
    }

    #[Test]
    public function it_pairs_nothing_when_only_one_player_turns_up(): void
    {
        $roster = $this->roster(1);
        $result = $this->pair($roster, [], $this->standings($roster));

        self::assertSame([], $result->pairings);
        self::assertSame([], $result->byes);
    }

    /**
     * @param list<SeasonPlayer>      $roster
     * @param list<Game>              $history
     * @param list<StandingsSnapshot> $standings
     */
    private function pair(array $roster, array $history, array $standings, ?KeizerPairingSettings $settings = null, array $categories = []): \SCS\Engine\Pairing\PairingResult
    {
        return (new KeizerPairing($settings ?? new KeizerPairingSettings()))
            ->pairNextRound($this->season($categories), $roster, $history, $standings);
    }

    /** @return list<SeasonPlayer> */
    private function roster(int $count): array
    {
        $roster = [];
        for ($i = 1; $i <= $count; $i++) {
            $roster[] = new SeasonPlayer(
                id:          $i,
                season_id:   1,
                player_id:   $i,
                category:    null,
                elo_rating:  2000 - $i * 25,
                enrolled_at: new \DateTimeImmutable('2026-09-01'),
            );
        }

        return $roster;
    }

    /**
     * @param  list<SeasonPlayer>      $roster
     * @return list<StandingsSnapshot>
     */
    private function standings(array $roster): array
    {
        $standings = [];
        foreach ($roster as $index => $player) {
            $standings[] = $this->snapshot($player->id, $index + 1);
        }

        return $standings;
    }

    private function snapshot(int $seasonPlayerId, int $rank, int $byes = 0): StandingsSnapshot
    {
        return new StandingsSnapshot(
            id:               0,
            season_id:        1,
            round_id:         1,
            season_player_id: $seasonPlayerId,
            rank:             $rank,
            keizer_score:     1000 - $rank * 10,
            classical_points: 0.0,
            wins:             0,
            draws:            0,
            losses:           0,
            games:            0,
            byes:             $byes,
            color_balance:    0,
            tpr:              null,
            scores:           [],
        );
    }

    private function game(int $id, int $white, int $black): Game
    {
        return new Game(
            id:                     $id,
            round_id:               1,
            board:                  $id,
            white_season_player_id: $white,
            black_season_player_id: $black,
            result:                 GameResult::Draw,
        );
    }

    /** @param list<string> $categories */
    private function season(array $categories = []): Season
    {
        return new Season(
            id:             1,
            name:           'Test',
            location:       null,
            start_date:     null,
            end_date:       null,
            pairing_system: PairingSystem::Keizer,
            status:         SeasonStatus::Active,
            categories:     $categories,
            created_at:     new \DateTimeImmutable('2026-09-01'),
        );
    }
}
