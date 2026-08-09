<?php

declare(strict_types=1);

namespace SCS\Tests\Unit\Engine\Scoring\Fixture;

use SCS\Entity\Attendance;
use SCS\Entity\Enum\AttendanceStatus;
use SCS\Entity\Enum\ByeType;
use SCS\Entity\Enum\GameResult;
use SCS\Entity\Enum\PairingSystem;
use SCS\Entity\Enum\RoundStatus;
use SCS\Entity\Enum\SeasonStatus;
use SCS\Entity\Game;
use SCS\Entity\Round;
use SCS\Entity\Season;
use SCS\Entity\SeasonPlayer;
use SCS\Entity\StandingsSnapshot;

/**
 * A shipped season fixture, loaded as entities.
 *
 * `fixtures/competition_2025_2026.json` is the club's real Keizer season as
 * played: every round's games with results, its byes, and the standings that
 * were published after it — including the Keizer score each player held. That
 * makes it an oracle rather than a sample, which is the only way to tell
 * whether this engine computes what the club's members were actually shown.
 *
 * Players are keyed by name in the JSON, so ids are assigned here in file order
 * and every reference resolved through them.
 */
final class SeasonFixture
{
    /** @var array<string,int> name => season_player_id */
    public readonly array $playerIds;

    /** @var list<SeasonPlayer> */
    public readonly array $roster;

    /** @var array<int,mixed> round number => decoded round */
    private readonly array $rounds;

    private function __construct(private readonly array $data)
    {
        $ids     = [];
        $roster  = [];
        $enrolled = new \DateTimeImmutable('2025-09-01');

        foreach (array_values($this->data['players']) as $index => $player) {
            $id                      = $index + 1;
            $ids[$player['name']]    = $id;
            $roster[]                = new SeasonPlayer(
                id:          $id,
                season_id:   1,
                player_id:   $id,
                category:    $player['category'] ?? null,
                elo_rating:  (int)($player['rating'] ?? 0),
                enrolled_at: $enrolled->modify('+' . $index . ' minutes'),
            );
        }

        $rounds = [];
        foreach ($this->data['rounds'] as $round) {
            $rounds[(int)$round['number']] = $round;
        }

        $this->playerIds = $ids;
        $this->roster    = $roster;
        $this->rounds    = $rounds;
    }

    public static function load(string $name): self
    {
        $path = dirname(__DIR__, 5) . '/fixtures/' . $name . '.json';
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("Fixture not readable: {$path}");
        }

        return new self(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    public function season(): Season
    {
        return new Season(
            id:             1,
            name:           (string)$this->data['season']['name'],
            location:       null,
            start_date:     null,
            end_date:       null,
            pairing_system: PairingSystem::Keizer,
            status:         SeasonStatus::Completed,
            categories:     $this->data['season']['categories'] ?? [],
            created_at:     new \DateTimeImmutable('2025-09-01'),
        );
    }

    /** @return list<int> the round numbers present, in order */
    public function roundNumbers(): array
    {
        $numbers = array_keys($this->rounds);
        sort($numbers);

        return array_values($numbers);
    }

    public function round(int $number): Round
    {
        return new Round(
            id:           $number,
            season_id:    1,
            round_number: $number,
            date:         null,
            status:       RoundStatus::Complete,
            created_at:   new \DateTimeImmutable('2025-09-01'),
        );
    }

    /** @return list<Game> */
    public function games(int $number): array
    {
        $games = [];
        foreach ($this->rounds[$number]['games'] as $index => $game) {
            $games[] = new Game(
                id:                     $number * 1000 + $index,
                round_id:               $number,
                board:                  (int)($game['board'] ?? $index + 1),
                white_season_player_id: $this->playerIds[$game['white']],
                black_season_player_id: $this->playerIds[$game['black']],
                result:                 $this->result($game['result'] ?? null),
            );
        }

        return $games;
    }

    /**
     * The round's byes, as attendance rows.
     *
     * The fixture records only that a player had a bye, not which kind. They are
     * read as pairing byes because that is what an odd field produces, and the
     * club's own history prices this round-1 bye at exactly the pairing-bye rate.
     *
     * @return list<Attendance>
     */
    public function attendance(int $number): array
    {
        $rows = [];
        foreach ($this->rounds[$number]['byes'] ?? [] as $index => $name) {
            $rows[] = new Attendance(
                id:               $number * 1000 + $index,
                round_id:         $number,
                season_player_id: $this->playerIds[$name],
                status:           AttendanceStatus::Present,
                bye_type:         ByeType::PairingBye,
            );
        }

        return $rows;
    }

    /**
     * The published standings after this round: season_player_id => row.
     *
     * @return array<int,array<string,mixed>>
     */
    public function standings(int $number): array
    {
        $rows = [];
        foreach ($this->rounds[$number]['standings'] as $row) {
            $rows[$this->playerIds[$row['name']]] = $row;
        }

        return $rows;
    }

    /**
     * The published standings after a round, as snapshot entities.
     *
     * Feeding these in as the previous round sidesteps our inability to
     * reconstruct Sevilla's opening order: from round two the ladder is ordered
     * by the round before, and here that order is the club's own.
     *
     * @return list<StandingsSnapshot>
     */
    public function publishedSnapshots(int $number): array
    {
        $snapshots = [];
        foreach ($this->rounds[$number]['standings'] as $row) {
            $snapshots[] = new StandingsSnapshot(
                id:               0,
                season_id:        1,
                round_id:         $number,
                season_player_id: $this->playerIds[$row['name']],
                rank:             (int)$row['rank'],
                keizer_score:     (int)$row['keizer_score'],
                classical_points: 0.0,
                wins:             (int)($row['wins'] ?? 0),
                draws:            (int)($row['draws'] ?? 0),
                losses:           (int)($row['losses'] ?? 0),
                games:            (int)($row['games'] ?? 0),
                byes:             (int)($row['byes'] ?? 0),
                color_balance:    (int)($row['color_balance'] ?? 0),
                tpr:              isset($row['tpr']) ? (int)$row['tpr'] : null,
                scores:           [],
            );
        }

        return $snapshots;
    }

    private function result(?string $result): ?GameResult
    {
        return match ($result) {
            'white' => GameResult::White,
            'black' => GameResult::Black,
            'draw'  => GameResult::Draw,
            default => null,
        };
    }
}
