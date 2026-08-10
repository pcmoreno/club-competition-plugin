<?php

declare(strict_types=1);

namespace SCS\Command;

use SCS\Exception\ConflictException;
use SCS\Services\AuthService;

class CreateAdminCommand
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    /**
     * Usage:
     *   wp scs create-admin --name="Admin Name" --email="admin@example.com" --password="secret"
     */
    public function __invoke(array $args, array $assoc_args): void
    {
        $name     = trim((string)($assoc_args['name'] ?? ''));
        $email    = trim((string)($assoc_args['email'] ?? ''));
        $password = (string)($assoc_args['password'] ?? '');

        if ($name === '') {
            \WP_CLI::error('--name is required.');
        }
        if ($email === '' || !is_email($email)) {
            \WP_CLI::error('--email is required and must be a valid email address.');
        }
        if (strlen($password) < 8) {
            \WP_CLI::error('--password is required and must be at least 8 characters.');
        }

        try {
            $admin = $this->authService->createAdmin($name, $email, $password);
        } catch (ConflictException $e) {
            \WP_CLI::error($e->getMessage());

            return;
        }

        \WP_CLI::success(sprintf('Admin created: %s <%s> (id %d).', $admin->name, $admin->email, $admin->id));
    }
}
