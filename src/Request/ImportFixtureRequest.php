<?php

declare(strict_types=1);

namespace SCS\Request;

use Symfony\Component\Validator\Constraints as Assert;

class ImportFixtureRequest
{
    #[Assert\NotBlank(message: 'Fixture name is required.')]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9_\-]+$/', message: 'Fixture name is not valid.')]
    public string $name = '';

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto       = new self();
        $dto->name = trim((string)$request->get_param('name'));

        return $dto;
    }
}
