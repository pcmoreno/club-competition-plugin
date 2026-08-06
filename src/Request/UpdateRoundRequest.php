<?php

declare(strict_types=1);

namespace SCS\Request;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateRoundRequest
{
    #[Assert\Date(message: 'Date must be in YYYY-MM-DD format.')]
    public ?string $date = null;

    // An absent param means "unchanged" and an empty one means "clear", so the
    // null in $date can't tell the two apart on its own.
    public bool $dateProvided = false;

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto = new self();

        if ($request->get_param('date') !== null) {
            $dto->dateProvided = true;
            $date              = (string)$request->get_param('date');
            $dto->date         = $date === '' ? null : $date;
        }

        return $dto;
    }
}
