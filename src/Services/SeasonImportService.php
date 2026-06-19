<?php

declare(strict_types=1);

namespace SCS\Services;

use Doctrine\DBAL\Connection;
use SCS\Entity\Enum\AttendanceStatus;
use SCS\Entity\Enum\ByeType;
use SCS\Entity\Enum\GameResult;
use SCS\Entity\Enum\PairingSystem;
use SCS\Entity\Enum\RoundStatus;
use SCS\Entity\Enum\SeasonStatus;
use SCS\Exception\NotFoundException;
use SCS\Exception\ValidationException;
use SCS\Repository\AttendanceRepository;
use SCS\Repository\GameRepository;
use SCS\Repository\PlayerRepository;
use SCS\Repository\RoundRepository;
use SCS\Repository\SeasonPlayerRepository;
use SCS\Repository\SeasonRepository;
use SCS\Repository\StandingsSnapshotRepository;

/**
 * Seeds a whole season into the wp_scs_ tables from a plugin-shipped JSON
 * fixture, through the repositories.
 *
 * Design (see dev/import-redesign.md):
 *  - players is a GLOBAL person registry keyed by name. We upsert by name —
 *    existing person keeps their row/id, new person is inserted — and NEVER
 *    delete/renumber players. That keeps members.player_id couplings valid
 *    across re-imports for free.
 *  - the import is SEASON-SCOPED: it find-or-creates the season (by name) and
 *    replaces only that season's enrolment/rounds/games/snapshots. Other
 *    seasons are untouched (multiple seasons may be active at once).
 *  - player references in the season data are resolved by name → id at load.
 *
 * The whole load runs in one transaction.
 */
class SeasonImportService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PlayerRepository $players,
        private readonly SeasonRepository $seasons,
        private readonly SeasonPlayerRepository $seasonPlayers,
        private readonly RoundRepository $rounds,
        private readonly GameRepository $games,
        private readonly AttendanceRepository $attendance,
        private readonly StandingsSnapshotRepository $snapshots,
        private readonly string $fixturesDir,
    ) {
    }

    /**
     * The fixtures available to import, each with the season name as description.
     *
     * @return list<array{name: string, description: string}>
     */
    public function availableFixtures(): array
    {
        $files = glob($this->fixturesDir . '/*.json') ?: [];
        sort($files);

        return array_map(function (string $path): array {
            $data = json_decode((string)file_get_contents($path), true);

            return [
                'name'        => basename($path, '.json'),
                'description' => is_array($data) ? (string)($data['season']['name'] ?? '') : '',
            ];
        }, $files);
    }

    /**
     * Import a fixture by name. Returns a summary of what was seeded.
     *
     * @return array<string, int>
     */
    public function import(string $name): array
    {
        $data = $this->read($name);

        return $this->connection->transactional(fn (): array => $this->apply($data));
    }

    /** @return array<string, int> */
    private function apply(array $data): array
    {
        $seasonId = $this->upsertSeason($data['season']);

        // Player registry: upsert by name first, so every name in the season
        // data resolves to a stable player id (existing ones keep their id).
        $playerIdByName = [];
        $playersCreated = 0;
        foreach ($data['players'] as $p) {
            $existing = $this->players->findByName($p['name']);
            if ($existing === null) {
                // Seed knsb_elo from the scraped rating for NEW people only; an
                // existing person's rating is owned by the KNSB sync, not the import.
                $existing = $this->players->create($p['name'], null, $p['rating'] ?? null, null, null);
                $playersCreated++;
            }
            $playerIdByName[$p['name']] = $existing->id;
        }

        // Replace only this season's scoped data.
        $this->snapshots->deleteBySeason($seasonId);
        $this->games->deleteBySeason($seasonId);
        $this->attendance->deleteBySeason($seasonId);
        $this->seasonPlayers->deleteBySeason($seasonId);
        $this->rounds->deleteBySeason($seasonId);

        // Enrolment: one season_player per roster entry, carrying the per-season
        // category + rating. Map name → season_player id for the games/snapshots.
        $seasonPlayerIdByName = [];
        foreach ($data['players'] as $p) {
            $sp = $this->seasonPlayers->create(
                $seasonId,
                $playerIdByName[$p['name']],
                $p['category'] ?? null,
                (int)($p['rating'] ?? 0),
            );
            $seasonPlayerIdByName[$p['name']] = $sp->id;
        }

        // Resolve a (possibly guest) player name to its enrolment id, enrolling
        // on the fly if a games-only participant isn't in the roster.
        $resolveEnrolment = function (string $playerName) use (&$playerIdByName, &$seasonPlayerIdByName, &$playersCreated, $seasonId): int {
            if (!isset($seasonPlayerIdByName[$playerName])) {
                if (!isset($playerIdByName[$playerName])) {
                    $player = $this->players->findByName($playerName);
                    if ($player === null) {
                        $player = $this->players->create($playerName, null, null, null, null);
                        $playersCreated++;
                    }
                    $playerIdByName[$playerName] = $player->id;
                }
                $sp = $this->seasonPlayers->create($seasonId, $playerIdByName[$playerName], null, 0);
                $seasonPlayerIdByName[$playerName] = $sp->id;
            }

            return $seasonPlayerIdByName[$playerName];
        };

        $counts = ['rounds' => 0, 'games' => 0, 'byes' => 0, 'snapshots' => 0];
        foreach ($data['rounds'] as $r) {
            $round = $this->rounds->create(
                $seasonId,
                (int)$r['number'],
                $r['date'] ?? null,
                RoundStatus::from($r['status'] ?? RoundStatus::Complete->value),
            );
            $counts['rounds']++;

            foreach ($r['games'] ?? [] as $g) {
                $this->games->create(
                    $round->id,
                    $resolveEnrolment($g['white']),
                    $resolveEnrolment($g['black']),
                    $g['board'] ?? null,
                    isset($g['result']) ? GameResult::from($g['result']) : null,
                );
                $counts['games']++;
            }

            foreach ($r['byes'] ?? [] as $byeName) {
                $this->attendance->save($round->id, $resolveEnrolment($byeName), AttendanceStatus::Present, ByeType::ParingBye);
                $counts['byes']++;
            }

            foreach ($r['standings'] ?? [] as $s) {
                $this->snapshots->create(
                    $seasonId,
                    $round->id,
                    $resolveEnrolment($s['name']),
                    (int)$s['rank'],
                    (int)$s['keizer_score'],
                    (float)((int)$s['wins'] + 0.5 * (int)$s['draws']),
                    (int)$s['wins'],
                    (int)$s['draws'],
                    (int)$s['losses'],
                    (int)$s['games'],
                    (int)$s['byes'],
                    (int)$s['color_balance'],
                    isset($s['tpr']) ? (int)$s['tpr'] : null,
                );
                $counts['snapshots']++;
            }
        }

        return ['players_created' => $playersCreated, 'season_players' => count($seasonPlayerIdByName)] + $counts;
    }

    /** Find-or-create the season by name, then sync its metadata. */
    private function upsertSeason(array $s): int
    {
        $season = $this->seasons->findByName($s['name']);
        if ($season === null) {
            $season = $this->seasons->create(
                $s['name'],
                $s['location'] ?? null,
                $s['start_date'] ?? null,
                $s['end_date'] ?? null,
                PairingSystem::from($s['pairing_system'] ?? PairingSystem::Keizer->value),
                $s['categories'] ?? [],
            );
        }

        $this->seasons->update($season->id, [
            'location'       => $s['location'] ?? null,
            'start_date'     => $s['start_date'] ?? null,
            'end_date'       => $s['end_date'] ?? null,
            'pairing_system' => (PairingSystem::from($s['pairing_system'] ?? PairingSystem::Keizer->value))->value,
            'status'         => (SeasonStatus::from($s['status'] ?? SeasonStatus::Active->value))->value,
            'categories'     => json_encode($s['categories'] ?? []),
        ]);

        return $season->id;
    }

    /** Whitelist the fixture name against the shipped files and decode it. */
    private function read(string $name): array
    {
        $available = array_column($this->availableFixtures(), 'name');
        if (!in_array($name, $available, true)) {
            throw new NotFoundException(sprintf('Unknown fixture "%s".', $name));
        }

        $data = json_decode((string)file_get_contents($this->fixturesDir . '/' . $name . '.json'), true);
        if (!is_array($data) || !isset($data['season']['name'], $data['players'], $data['rounds'])) {
            throw new ValidationException(['fixture' => 'Fixture is malformed.']);
        }

        return $data;
    }
}
