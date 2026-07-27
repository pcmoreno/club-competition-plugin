<?php

declare(strict_types=1);

namespace SCS\Request;

use Symfony\Component\Validator\Constraints as Assert;

// Bulk category assignment (Auto Fill): a list of {player_id, category} pairs.
// A null/empty category unassigns; a given category is checked against the
// season's set in the controller (it needs the season to validate).
class AssignCategoriesRequest
{
    /** @var list<array<string,mixed>>|null */
    #[Assert\NotNull(message: 'assignments is required.')]
    #[Assert\Type(type: 'array', message: 'assignments must be an array.')]
    #[Assert\Count(min: 1, minMessage: 'At least one assignment is required.')]
    #[Assert\All([
        new Assert\Collection(
            fields: [
                'player_id' => [
                    new Assert\NotBlank(message: 'player_id is required.'),
                    new Assert\Positive(message: 'player_id must be a positive integer.'),
                ],
                'category' => new Assert\Optional(),
            ],
            allowMissingFields: false,
            allowExtraFields: false,
        ),
    ])]
    public ?array $assignments = null;

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto     = new self();
        $entries = $request->get_param('assignments');
        $dto->assignments = is_array($entries) ? array_values($entries) : null;

        return $dto;
    }
}
