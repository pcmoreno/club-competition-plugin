<?php

declare(strict_types=1);

namespace SCS\Request;

use Symfony\Component\Validator\Constraints as Assert;

// The rating a player is enrolled in one tournament with.
class SetEnrolmentRatingRequest
{
    #[Assert\NotNull(message: 'elo_rating is required and must be a whole number.')]
    #[Assert\PositiveOrZero(message: 'Elo rating must be zero or positive.')]
    public ?int $elo_rating = null;

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto = new self();

        // Unreadable stays null so NotNull rejects it, rather than casting "abc" to 0.
        $dto->elo_rating = filter_var(
            $request->get_param('elo_rating'),
            FILTER_VALIDATE_INT,
            FILTER_NULL_ON_FAILURE
        );

        return $dto;
    }
}
