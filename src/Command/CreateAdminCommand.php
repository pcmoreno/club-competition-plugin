<?php

declare(strict_types=1);

namespace SCS\Command;

use SCS\Repository\AdminRepository;

class CreateAdminCommand
{
    public function __construct(private readonly AdminRepository $adminRepository)
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

        if ($this->adminRepository->findByEmail($email) !== null) {
            \WP_CLI::error(sprintf('An admin with email "%s" already exists.', $email));
        }

        $admin = $this->adminRepository->create(
            $name,
            $email,
            password_hash($password, PASSWORD_BCRYPT),
        );

        \WP_CLI::success(sprintf('Admin created: %s <%s> (id %d).', $admin->name, $admin->email, $admin->id));
    }
}
