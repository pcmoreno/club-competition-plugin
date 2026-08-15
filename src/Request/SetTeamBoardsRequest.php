<?php

declare(strict_types=1);

namespace SCS\Request;

use Symfony\Component\Validator\Constraints as Assert;

// One team's board order, as the players in the order they play. The server
// numbers them 1..n, so contiguity and uniqueness aren't the client's to get right.
class SetTeamBoardsRequest
{
    #[Assert\NotBlank(message: 'A team is required.')]
    public ?string $team = null;

    /** @var list<int>|null */
    #[Assert\NotNull(message: 'player_ids is required.')]
    #[Assert\Type(type: 'array', message: 'player_ids must be an array.')]
    #[Assert\All([
        new Assert\Positive(message: 'Each player id must be a positive integer.'),
    ])]
    #[Assert\Unique(message: 'A player can only hold one board.')]
    #[Assert\Count(max: 255, maxMessage: 'A team cannot have more than {{ limit }} boards.')]
    public ?array $player_ids = null;

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto  = new self();
        $team = $request->get_param('team');
        $ids  = $request->get_param('player_ids');

        $dto->team       = $team === null ? null : trim((string)$team);
        $dto->player_ids = is_array($ids) ? array_values(array_map('intval', $ids)) : null;

        return $dto;
    }
}
