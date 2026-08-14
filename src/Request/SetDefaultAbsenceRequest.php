<?php

declare(strict_types=1);

namespace SCS\Request;

use Symfony\Component\Validator\Constraints as Assert;

// Move enrolments between default present and default absent: which players, and which way.
class SetDefaultAbsenceRequest
{
    /** @var list<int>|null */
    #[Assert\NotNull(message: 'player_ids is required.')]
    #[Assert\Type(type: 'array', message: 'player_ids must be an array.')]
    #[Assert\Count(min: 1, minMessage: 'At least one player id is required.')]
    #[Assert\All([
        new Assert\Positive(message: 'Each player id must be a positive integer.'),
    ])]
    public ?array $player_ids = null;

    #[Assert\NotNull(message: 'default_absent is required.')]
    public ?bool $default_absent = null;

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto             = new self();
        $ids             = $request->get_param('player_ids');
        $dto->player_ids = is_array($ids) ? array_values(array_map('intval', $ids)) : null;

        // Missing or unreadable stays null so NotNull rejects it: defaulting either
        // way would silently move players in a direction the caller didn't ask for.
        $dto->default_absent = filter_var(
            $request->get_param('default_absent'),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        return $dto;
    }
}
