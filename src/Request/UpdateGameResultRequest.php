<?php

declare(strict_types=1);

namespace SCS\Request;

use SCS\Entity\Enum\GameResult;
use Symfony\Component\Validator\Constraints as Assert;

class UpdateGameResultRequest
{
    #[Assert\Choice(callback: [self::class, 'resultChoices'], message: 'Result is not valid.')]
    public ?string $result = null;

    // Whether the caller sent the field at all. Absent and null have to stay
    // distinct here: null is a deliberate "clear this result", while absent is a
    // malformed request — and treating the two alike made a bodyless PATCH erase
    // a recorded result instead of being rejected.
    public bool $resultProvided = false;

    /** @return list<string> */
    public static function resultChoices(): array
    {
        return array_column(GameResult::cases(), 'value');
    }

    #[Assert\IsTrue(message: 'A result is required. Send result: null to clear one.')]
    public function hasResultField(): bool
    {
        return $this->resultProvided;
    }

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto = new self();

        // Key presence, so an explicit `result: null` (clear the result) is
        // still distinguishable from omitting the field entirely.
        $dto->resultProvided = $request->has_param('result');

        $raw = $request->get_param('result');
        if ($raw !== null) {
            $dto->result = (string)$raw;
        }

        return $dto;
    }
}
