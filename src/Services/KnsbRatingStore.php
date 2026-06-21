<?php

declare(strict_types=1);

namespace SCS\Services;

/**
 * Persists the fetched KNSB rating list as a JSON file under
 * resources/KnsbRatings/, decoupling the monthly fetch (CLI/cron) from applying
 * ratings to players (admin UI). The fetch overwrites the file; the per-player
 * "sync rating" action reads a single relatienummer out of it.
 *
 * A file (not a DB table) keeps the ~20k-row list out of the schema and lets the
 * fetch and apply halves ship independently.
 */
class KnsbRatingStore
{
    private const FILE = 'klassiek.json';

    public function __construct(private readonly string $ratingsDir)
    {
    }

    /**
     * @param array{list_date: ?string, ratings: array<string, array{rating: int, name: string}>} $fetched
     */
    public function write(array $fetched): void
    {
        if (!is_dir($this->ratingsDir) && !mkdir($this->ratingsDir, 0775, true) && !is_dir($this->ratingsDir)) {
            throw new \RuntimeException(sprintf('Could not create ratings dir "%s".', $this->ratingsDir));
        }

        $payload = [
            'list_date'  => $fetched['list_date'] ?? null,
            'fetched_at' => current_time('mysql'),
            'ratings'    => $fetched['ratings'],
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Could not encode the KNSB rating list.');
        }

        if (file_put_contents($this->path(), $json) === false) {
            throw new \RuntimeException('Could not write the KNSB rating list.');
        }
    }

    /**
     * The whole stored list, or null when nothing has been fetched yet.
     *
     * @return array{list_date: ?string, fetched_at: ?string, ratings: array<string, array{rating: int, name: string}>}|null
     */
    public function read(): ?array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) {
            return null;
        }

        /** @var array{list_date: ?string, fetched_at: ?string, ratings: array<string, array{rating: int, name: string}>} $data */
        return $data;
    }

    /**
     * One player's row by relatienummer, or null if not fetched / not listed.
     *
     * @return array{rating: int, name: string}|null
     */
    public function findRating(string $knsbId): ?array
    {
        $data = $this->read();
        $row  = $data['ratings'][$knsbId] ?? null;

        return is_array($row) ? $row : null;
    }

    /**
     * The list's provenance (date + when fetched), or null if never fetched.
     *
     * @return array{list_date: ?string, fetched_at: ?string}|null
     */
    public function meta(): ?array
    {
        $data = $this->read();
        if ($data === null) {
            return null;
        }

        return [
            'list_date'  => $data['list_date'] ?? null,
            'fetched_at' => $data['fetched_at'] ?? null,
        ];
    }

    private function path(): string
    {
        return rtrim($this->ratingsDir, '/') . '/' . self::FILE;
    }
}
