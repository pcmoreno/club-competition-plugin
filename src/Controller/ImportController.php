<?php

declare(strict_types=1);

namespace SCS\Controller;

use SCS\Request\ImportFixtureRequest;
use SCS\Services\SeasonImportService;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ImportController extends RestController
{
    public function __construct(
        ValidatorInterface $validator,
        private readonly SeasonImportService $importService,
    ) {
        parent::__construct($validator);
    }

    public function index(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () {
            return $this->ok($this->importService->availableFixtures());
        });
    }

    public function load(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $input = ImportFixtureRequest::fromRequest($request);
            $this->validate($input);

            return $this->ok($this->importService->import($input->name));
        });
    }
}
