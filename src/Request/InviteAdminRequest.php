<?php

declare(strict_types=1);

namespace SCS\Request;

use Symfony\Component\Validator\Constraints as Assert;

class InviteAdminRequest
{
    #[Assert\NotBlank(message: 'Name is required.')]
    #[Assert\Length(max: 255, maxMessage: 'Name must be at most {{ limit }} characters.')]
    public string $name = '';

    #[Assert\NotBlank(message: 'Email is required.')]
    #[Assert\Email(message: 'Enter a valid email address.')]
    public string $email = '';

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto        = new self();
        $dto->name  = trim((string)$request->get_param('name'));
        $dto->email = trim((string)$request->get_param('email'));

        return $dto;
    }
}
