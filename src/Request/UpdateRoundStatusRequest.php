<?php

declare(strict_types=1);

namespace SCS\Request;

use SCS\Entity\Enum\RoundStatus;
use Symfony\Component\Validator\Constraints as Assert;

class UpdateRoundStatusRequest
{
    #[Assert\NotBlank(message: 'Status is required.')]
    #[Assert\Choice(callback: [self::class, 'statusChoices'], message: 'Status is not valid.')]
    public string $status = '';

    // Close the tournament along with this round; only meaningful with 'complete'.
    public bool $complete_season = false;

    /** @return list<string> */
    public static function statusChoices(): array
    {
        return array_column(RoundStatus::cases(), 'value');
    }

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto         = new self();
        $dto->status = (string)$request->get_param('status');
        // Closing a tournament can't be undone, and get_param also resolves query
        // args and form bodies, where (bool) reads "false" and "off" as true — so
        // only an affirmative boolean closes it.
        $dto->complete_season = filter_var(
            $request->get_param('complete_season'),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) === true;

        return $dto;
    }
}
