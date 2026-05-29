<?php

declare(strict_types=1);

namespace SCS\Request;

use Symfony\Component\Validator\Constraints as Assert;

class ResetPasswordRequest
{
    #[Assert\NotBlank(message: 'Token is required.')]
    public string $token = '';

    #[Assert\NotBlank(message: 'Password is required.')]
    #[Assert\Length(min: 8, minMessage: 'Password must be at least {{ limit }} characters.')]
    public string $password = '';

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto = new self();
        $dto->token    = trim((string)$request->get_param('token'));
        $dto->password = (string)$request->get_param('password');

        return $dto;
    }
}
