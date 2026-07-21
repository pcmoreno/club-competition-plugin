<?php

declare(strict_types=1);

namespace SCS\Request;

use Symfony\Component\Validator\Constraints as Assert;

class UpdatePairingRequest
{
    #[Assert\Positive(message: 'White player is not valid.')]
    public ?int $white_season_player_id = null;

    #[Assert\Positive(message: 'Black player is not valid.')]
    public ?int $black_season_player_id = null;

    #[Assert\PositiveOrZero(message: 'Board must be zero or greater.')]
    public ?int $board = null;

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto = new self();
        if ($request->get_param('white_season_player_id') !== null) {
            $dto->white_season_player_id = (int)$request->get_param('white_season_player_id');
        }
        if ($request->get_param('black_season_player_id') !== null) {
            $dto->black_season_player_id = (int)$request->get_param('black_season_player_id');
        }
        if ($request->get_param('board') !== null) {
            $dto->board = (int)$request->get_param('board');
        }

        return $dto;
    }
}
