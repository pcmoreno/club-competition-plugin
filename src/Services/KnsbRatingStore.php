<?php

declare(strict_types=1);

namespace SCS\Services;

/**
 * Persists the fetched KNSB rating list as a JSON file, decoupling the monthly
 * fetch (CLI/cron) from applying ratings to players (admin UI). The fetch
 * overwrites the file; the per-player "sync rating" action reads a single
 * relatienummer out of it.
 *
 * A file (not a DB table) keeps the ~20k-row list out of the schema and lets the
 * fetch and apply halves ship independently.
 *
 * The list holds ~20,000 federation members' relatienummer, name, rating and
 * birth year — personal data of people who are not users of this site — so it
 * must not be web-reachable. It lives under uploads/ (which survives the
 * git-pull deploy, unlike the plugin dir) in a directory that is hardened three
 * ways: an index.php, an .htaccess deny rule, and a per-site random suffix so
 * the path is unguessable even on a server that ignores .htaccess.
 */
class KnsbRatingStore
{
    private const FILE = 'klassiek.json';

    // Per-site random suffix for the storage directory, generated on first use.
    private const DIR_SUFFIX_OPTION = 'scs_knsb_dir_suffix';

    private ?string $resolvedDir = null;

    /**
     * @param string $uploadsBaseDir wp_upload_dir()['basedir']
     * @param string $legacyDir      pre-0.3.3 location inside the plugin, migrated on first use
     */
    public function __construct(
        private readonly string $uploadsBaseDir,
        private readonly string $legacyDir,
    ) {
    }

    /**
     * @param array{list_date: ?string, ratings: array<string, array{rating: int, name: string, birth_year: ?int}>} $fetched
     */
    public function write(array $fetched): void
    {
        $dir = $this->dir();

        $payload = [
            'list_date'  => $fetched['list_date'] ?? null,
            'fetched_at' => current_time('mysql'),
            'ratings'    => $fetched['ratings'],
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Could not encode the KNSB rating list.');
        }

        // Write beside the target and swap: readers never see a half-written list,
        // and replacing only needs a writable dir (the old file may be another user's).
        $tmp = tempnam($dir, 'klassiek');
        if ($tmp === false || file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException('Could not write the KNSB rating list.');
        }

        @chmod($tmp, 0664);

        if (!rename($tmp, $this->path())) {
            @unlink($tmp);

            throw new \RuntimeException('Could not replace the KNSB rating list.');
        }
    }

    /**
     * The whole stored list, or null when nothing has been fetched yet.
     *
     * @return array{list_date: ?string, fetched_at: ?string, ratings: array<string, array{rating: int, name: string, birth_year: ?int}>}|null
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

        /** @var array{list_date: ?string, fetched_at: ?string, ratings: array<string, array{rating: int, name: string, birth_year: ?int}>} $data */
        return $data;
    }

    /**
     * One player's row by relatienummer, or null if not fetched / not listed.
     *
     * @return array{rating: int, name: string, birth_year: ?int}|null
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
        return $this->dir() . '/' . self::FILE;
    }

    /**
     * The storage directory, created and hardened on first use, with any
     * pre-0.3.3 file moved in from the old plugin-dir location.
     */
    private function dir(): string
    {
        if ($this->resolvedDir !== null) {
            return $this->resolvedDir;
        }

        $suffix = get_option(self::DIR_SUFFIX_OPTION);
        if (!is_string($suffix) || $suffix === '') {
            $suffix = bin2hex(random_bytes(8));
            // autoload=false: read only when the KNSB list is actually touched.
            add_option(self::DIR_SUFFIX_OPTION, $suffix, '', false);
            // Re-read: a concurrent request may have won the add_option race.
            $stored = get_option(self::DIR_SUFFIX_OPTION);
            $suffix = is_string($stored) && $stored !== '' ? $stored : $suffix;
        }

        $dir = rtrim($this->uploadsBaseDir, '/') . '/scs-knsb-' . $suffix;

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create the KNSB ratings dir "%s".', $dir));
        }

        $this->harden($dir);
        $this->migrateLegacyFile($dir);

        $this->resolvedDir = $dir;

        return $dir;
    }

    // Belt and braces: .htaccess covers Apache, index.php stops a directory
    // listing anywhere. Neither is relied on alone — the random suffix is what
    // protects the file on a server that serves uploads without .htaccess.
    private function harden(string $dir): void
    {
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        if (!is_file($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
    }

    /**
     * Move a list written by an earlier version out of the plugin directory,
     * where it was fetchable over HTTP. Moved rather than deleted so the fetch
     * doesn't have to be repeated; a failed move is not fatal (the admin can
     * simply re-fetch), but the exposed copy is unlinked either way.
     */
    private function migrateLegacyFile(string $dir): void
    {
        $legacy = rtrim($this->legacyDir, '/') . '/' . self::FILE;
        if (!is_file($legacy)) {
            return;
        }

        if (!is_file($dir . '/' . self::FILE)) {
            @rename($legacy, $dir . '/' . self::FILE);
        }

        @unlink($legacy);
    }
}
