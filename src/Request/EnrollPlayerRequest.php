<?php

declare(strict_types=1);

namespace SCS\Request;

use Symfony\Component\Validator\Constraints as Assert;

class EnrollPlayerRequest
{
    #[Assert\Positive(message: 'Player id is required.')]
    public int $player_id = 0;

    #[Assert\NotBlank(message: 'Category is required.')]
    public string $category = '';

    #[Assert\PositiveOrZero(message: 'Elo rating must be zero or positive.')]
    public ?int $elo_rating = null;

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto             = new self();
        $dto->player_id  = (int)$request->get_param('player_id');
        $dto->category   = trim((string)($request->get_param('category') ?? ''));

        if ($request->get_param('elo_rating') !== null) {
            $dto->elo_rating = (int)$request->get_param('elo_rating');
        }

        return $dto;
    }
}
