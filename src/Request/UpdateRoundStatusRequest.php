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

    /** @return list<string> */
    public static function statusChoices(): array
    {
        return array_column(RoundStatus::cases(), 'value');
    }

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto         = new self();
        $dto->status = (string)$request->get_param('status');

        return $dto;
    }
}
