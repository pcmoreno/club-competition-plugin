<?php

declare(strict_types=1);

namespace SCS\Controller;

use SCS\Exception\ConflictException;
use SCS\Services\KnsbRatingListFetcher;
use SCS\Services\KnsbRatingStore;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The KNSB rating list itself (not per-player application, which lives on
 * PlayerController). `status` reports the currently stored list's provenance so
 * the admin sees which file is loaded before refetching; `fetch` downloads the
 * latest list server-side (the host has no CLI/cron to run the fetch command).
 * The frontend never calls schaakbond.nl directly.
 */
class KnsbController extends RestController
{
    public function __construct(
        ValidatorInterface $validator,
        private readonly KnsbRatingListFetcher $fetcher,
        private readonly KnsbRatingStore $store,
    ) {
        parent::__construct($validator);
    }

    public function status(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(fn () => $this->ok($this->currentStatus()));
    }

    public function fetch(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () {
            try {
                $fetched = $this->fetcher->fetch();
                $this->store->write($fetched);
            } catch (\RuntimeException $e) {
                // The fetcher/store throw \RuntimeException with a specific
                // reason (download HTTP error, bad archive, unwritable dir).
                // Surface it as a 409 so the admin sees what went wrong, instead
                // of the generic 500 the base handler gives an uncaught throw.
                throw new ConflictException($e->getMessage());
            }

            return $this->ok($this->currentStatus());
        });
    }

    /** @return array{available: bool, list_date: ?string, fetched_at: ?string, count: int} */
    private function currentStatus(): array
    {
        $data = $this->store->read();
        if ($data === null) {
            return ['available' => false, 'list_date' => null, 'fetched_at' => null, 'count' => 0];
        }

        return [
            'available'  => true,
            'list_date'  => $data['list_date'] ?? null,
            'fetched_at' => $data['fetched_at'] ?? null,
            'count'      => count($data['ratings']),
        ];
    }
}
