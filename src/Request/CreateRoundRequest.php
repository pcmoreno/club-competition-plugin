<?php

declare(strict_types=1);

namespace SCS\Request;

use Symfony\Component\Validator\Constraints as Assert;

class CreateRoundRequest
{
    #[Assert\Date(message: 'Date must be in YYYY-MM-DD format.')]
    public ?string $date = null;

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto = new self();

        if ($request->get_param('date') !== null) {
            $dto->date = (string)$request->get_param('date');
        }

        return $dto;
    }
}
