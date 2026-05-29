<?php

declare(strict_types=1);

namespace SCS\Request;

use Symfony\Component\Validator\Constraints as Assert;

class LoginRequest
{
    #[Assert\NotBlank(message: 'Email is required.')]
    #[Assert\Email(message: 'Email is not valid.')]
    public string $email = '';

    #[Assert\NotBlank(message: 'Password is required.')]
    public string $password = '';

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto = new self();
        $dto->email    = trim((string)$request->get_param('email'));
        $dto->password = (string)$request->get_param('password');

        return $dto;
    }
}
