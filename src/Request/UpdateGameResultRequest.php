<?php

declare(strict_types=1);

namespace SCS\Request;

use SCS\Entity\Enum\GameResult;
use Symfony\Component\Validator\Constraints as Assert;

class UpdateGameResultRequest
{
    #[Assert\Choice(callback: [self::class, 'resultChoices'], message: 'Result is not valid.')]
    public ?string $result = null;

    /** @return list<string> */
    public static function resultChoices(): array
    {
        return array_column(GameResult::cases(), 'value');
    }

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto = new self();

        if ($request->get_param('result') !== null) {
            $dto->result = (string)$request->get_param('result');
        }

        return $dto;
    }
}
