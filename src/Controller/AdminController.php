<?php

declare(strict_types=1);

namespace SCS\Controller;

use SCS\Repository\AdminRepository;
use SCS\Services\SerializerService;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The plugin's own admin accounts. Read-only for now — admins are created with
 * `wp scs create-admin` or the bootstrap endpoint — and it exists so the
 * tournament-contacts picker has a list to choose from.
 *
 * Admin-gated: these are staff names and email addresses.
 */
class AdminController extends RestController
{
    public function __construct(
        ValidatorInterface $validator,
        private readonly AdminRepository $adminRepository,
        private readonly SerializerService $serializer,
    ) {
        parent::__construct($validator);
    }

    /** Active admins only: a revoked account can't be picked as a recipient. */
    public function index(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(fn () => $this->ok($this->serializer->serializeMany(
            $this->adminRepository->findAllActive(),
            SerializerService::GROUP_ADMIN
        )));
    }
}
