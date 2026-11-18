<?php

declare(strict_types=1);

namespace SCS\Services;

/**
 * Downloads and parses the KNSB monthly "Klassiek" (classical) rating list.
 *
 * The federation publishes a ZIP holding a single semicolon-delimited,
 * latin-1 CSV with the header
 *   Relatienummer;Naam;Titel;FED;Rating;Nv;Geboren;S
 * where Relatienummer is the player's KNSB id (our `knsb_id`) and Rating is the
 * classical rating (our `knsb_elo`). The CSV's zip-entry mtime is the list date.
 *
 * Each month's list is a new upload under that month's folder, named either
 * `KLASSIEK.zip` or `2026-10-KLASSIEK.zip` with the CSV inside named to match,
 * so no fixed URL stays current. The newest one is found through the site's
 * public media search, with the last known file as a fallback.
 *
 * This only fetches + parses; persistence is KnsbRatingStore, application to
 * players is the sync endpoint. The frontend never calls KNSB directly.
 */
class KnsbRatingListFetcher
{
    private const MEDIA_SEARCH = 'https://schaakbond.nl/wp-json/wp/v2/media?search=KLASSIEK&orderby=date&order=desc&per_page=20&_fields=source_url';

    // Only ever download from the federation's own uploads, whatever the search returns.
    private const UPLOADS = 'https://schaakbond.nl/wp-content/uploads/';

    private const FALLBACK_URL = 'https://schaakbond.nl/wp-content/uploads/2026/10/KLASSIEK.zip';

    private const CSV_SUFFIX = 'klassiek.csv';

    /**
     * @return array{list_date: ?string, ratings: array<string, array{rating: int, name: string, birth_year: ?int}>}
     *
     * @throws \RuntimeException on a download, archive, or format failure
     */
    public function fetch(): array
    {
        $csv = $this->download();

        return $this->parse($csv['contents'], $csv['list_date']);
    }

    /** @return array{contents: string, list_date: ?string} */
    private function download(): array
    {
        $response = wp_remote_get($this->latestListUrl(), [ 'timeout' => 30 ]);
        if (is_wp_error($response)) {
            throw new \RuntimeException('KNSB download failed: ' . $response->get_error_message());
        }
        $status = (int)wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            throw new \RuntimeException(sprintf('KNSB download failed: HTTP %d.', $status));
        }

        // ZipArchive needs a file path, so the body goes to a temp file we own.
        // Not wp_tempnam(): that lives in wp-admin and isn't loaded on REST requests.
        $tmp = tempnam(get_temp_dir(), 'scs-knsb');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create a temp file for the KNSB archive.');
        }

        try {
            file_put_contents($tmp, wp_remote_retrieve_body($response));

            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                throw new \RuntimeException('KNSB archive could not be opened.');
            }

            $contents = false;
            $stat     = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                if ($entry !== false && str_ends_with(strtolower($entry['name']), self::CSV_SUFFIX)) {
                    $contents = $zip->getFromIndex($i);
                    $stat     = $entry;

                    break;
                }
            }
            $zip->close();

            if ($contents === false) {
                throw new \RuntimeException('No KLASSIEK CSV found in the KNSB archive.');
            }

            $listDate = isset($stat['mtime']) ? gmdate('Y-m-d', (int)$stat['mtime']) : null;

            return [ 'contents' => $contents, 'list_date' => $listDate ];
        } finally {
            @unlink($tmp);
        }
    }

    // Newest first, so the first KLASSIEK zip the search returns is this month's.
    private function latestListUrl(): string
    {
        $response = wp_remote_get(self::MEDIA_SEARCH, [ 'timeout' => 15 ]);
        if (is_wp_error($response) || (int)wp_remote_retrieve_response_code($response) !== 200) {
            return self::FALLBACK_URL;
        }

        $media = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($media)) {
            return self::FALLBACK_URL;
        }

        foreach ($media as $item) {
            $url = is_array($item) && is_string($item['source_url'] ?? null) ? $item['source_url'] : '';
            if (str_starts_with($url, self::UPLOADS) && preg_match('/klassiek\.zip$/i', $url) === 1) {
                return $url;
            }
        }

        return self::FALLBACK_URL;
    }

    /**
     * @return array{list_date: ?string, ratings: array<string, array{rating: int, name: string, birth_year: ?int}>}
     */
    private function parse(string $csv, ?string $listDate): array
    {
        // The list is latin-1 (Windows-1252); normalise to UTF-8 so names store
        // and display correctly.
        $csv   = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];

        $header = trim((string)($lines[0] ?? ''));
        if (strncmp($header, 'Relatienummer;Naam', 18) !== 0) {
            throw new \RuntimeException('Unexpected KNSB list format (header mismatch).');
        }

        $ratings = [];
        foreach (array_slice($lines, 1) as $line) {
            if ($line === '') {
                continue;
            }
            $cols = explode(';', $line);
            $id   = trim((string)($cols[0] ?? ''));
            $rating = (int)trim((string)($cols[4] ?? ''));
            if ($id === '' || $rating <= 0) {
                continue;
            }
            // Header: Relatienummer;Naam;Titel;FED;Rating;Nv;Geboren;S
            // "Geboren" is a plain birth year (e.g. 1966); blank for some rows.
            $birthYear = (int)trim((string)($cols[6] ?? ''));
            $ratings[$id] = [
                'rating'     => $rating,
                'name'       => trim((string)($cols[1] ?? '')),
                'birth_year' => $birthYear > 0 ? $birthYear : null,
            ];
        }

        return [ 'list_date' => $listDate, 'ratings' => $ratings ];
    }
}
