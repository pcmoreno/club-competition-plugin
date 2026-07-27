<?php

declare(strict_types=1);

namespace SCS\Request;

use Symfony\Component\Validator\Constraints as Assert;

// Bulk enrol / bulk remove: a plain list of player ids to act on in one request.
class BulkPlayerIdsRequest
{
    /** @var list<int>|null */
    #[Assert\NotNull(message: 'player_ids is required.')]
    #[Assert\Type(type: 'array', message: 'player_ids must be an array.')]
    #[Assert\Count(min: 1, minMessage: 'At least one player id is required.')]
    #[Assert\All([
        new Assert\Positive(message: 'Each player id must be a positive integer.'),
    ])]
    public ?array $player_ids = null;

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto  = new self();
        $ids  = $request->get_param('player_ids');
        $dto->player_ids = is_array($ids) ? array_values(array_map('intval', $ids)) : null;

        return $dto;
    }
}
