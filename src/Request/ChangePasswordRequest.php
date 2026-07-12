<?php

declare(strict_types=1);

namespace SCS\Request;

use Symfony\Component\Validator\Constraints as Assert;

class ChangePasswordRequest
{
    #[Assert\NotBlank(message: 'Current password is required.')]
    public string $currentPassword = '';

    #[Assert\NotBlank(message: 'New password is required.')]
    #[Assert\Length(min: 8, minMessage: 'Password must be at least {{ limit }} characters.')]
    public string $newPassword = '';

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto = new self();
        // Passwords are taken verbatim (no trim) — spaces are valid characters
        // and stored hashes must match exactly what the user typed.
        $dto->currentPassword = (string)$request->get_param('current_password');
        $dto->newPassword     = (string)$request->get_param('new_password');

        return $dto;
    }
}
