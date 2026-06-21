<?php

declare(strict_types=1);

namespace SCS\Command;

use SCS\Services\KnsbRatingListFetcher;
use SCS\Services\KnsbRatingStore;

class FetchKnsbRatingsCommand
{
    public function __construct(
        private readonly KnsbRatingListFetcher $fetcher,
        private readonly KnsbRatingStore $store,
    ) {
    }

    /**
     * Download the KNSB monthly classical rating list and store it for the admin
     * UI to apply per player. Does NOT change any player's rating.
     *
     * Usage:
     *   wp scs fetch-knsb-ratings
     */
    public function __invoke(array $args, array $assoc_args): void
    {
        try {
            $fetched = $this->fetcher->fetch();
            $this->store->write($fetched);
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());

            return;
        }

        \WP_CLI::success(sprintf(
            'Fetched %d KNSB ratings (list %s) → resources/KnsbRatings/klassiek.json',
            count($fetched['ratings']),
            $fetched['list_date'] ?? 'unknown date',
        ));
    }
}
