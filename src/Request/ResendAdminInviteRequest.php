<?php

declare(strict_types=1);

namespace SCS\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A resend carries only the email, which the form prefills with the current one
 * so a typo can be corrected on the way — the name is edited nowhere else, so
 * it is deliberately not re-submitted here.
 */
class ResendAdminInviteRequest
{
    #[Assert\NotBlank(message: 'Email is required.')]
    #[Assert\Email(message: 'Enter a valid email address.')]
    public string $email = '';

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto        = new self();
        $dto->email = trim((string)$request->get_param('email'));

        return $dto;
    }
}
