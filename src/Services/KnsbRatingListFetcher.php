<?php

declare(strict_types=1);

namespace SCS\Services;

/**
 * Downloads and parses the KNSB monthly "Klassiek" (classical) rating list.
 *
 * The federation publishes a ZIP holding a single semicolon-delimited,
 * latin-1 CSV (`KLASSIEK.csv`) with the header
 *   Relatienummer;Naam;Titel;FED;Rating;Nv;Geboren;S
 * where Relatienummer is the player's KNSB id (our `knsb_id`) and Rating is the
 * classical rating (our `knsb_elo`). Refreshed monthly on the 1st; the same URL
 * always serves the latest file, and the CSV's zip-entry mtime is the list date.
 *
 * This only fetches + parses; persistence is KnsbRatingStore, application to
 * players is the sync endpoint. The frontend never calls KNSB directly.
 */
class KnsbRatingListFetcher
{
    public const URL = 'https://schaakbond.nl/wp-content/uploads/2024/12/KLASSIEK.zip';

    private const CSV_NAME = 'KLASSIEK.csv';

    /**
     * @return array{list_date: ?string, ratings: array<string, array{rating: int, name: string}>}
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
        $response = wp_remote_get(self::URL, [ 'timeout' => 30 ]);
        if (is_wp_error($response)) {
            throw new \RuntimeException('KNSB download failed: ' . $response->get_error_message());
        }
        $status = (int)wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            throw new \RuntimeException(sprintf('KNSB download failed: HTTP %d.', $status));
        }

        // ZipArchive needs a file path, so the body goes to a temp file we own.
        $tmp = wp_tempnam('scs-knsb');
        if ($tmp === '') {
            throw new \RuntimeException('Could not create a temp file for the KNSB archive.');
        }

        try {
            file_put_contents($tmp, wp_remote_retrieve_body($response));

            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                throw new \RuntimeException('KNSB archive could not be opened.');
            }

            $contents = $zip->getFromName(self::CSV_NAME);
            $stat     = $zip->statName(self::CSV_NAME);
            $zip->close();

            if ($contents === false) {
                throw new \RuntimeException(sprintf('"%s" not found in the KNSB archive.', self::CSV_NAME));
            }

            $listDate = isset($stat['mtime']) ? gmdate('Y-m-d', (int)$stat['mtime']) : null;

            return [ 'contents' => $contents, 'list_date' => $listDate ];
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @return array{list_date: ?string, ratings: array<string, array{rating: int, name: string}>}
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
            $ratings[$id] = [
                'rating' => $rating,
                'name'   => trim((string)($cols[1] ?? '')),
            ];
        }

        return [ 'list_date' => $listDate, 'ratings' => $ratings ];
    }
}
