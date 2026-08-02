<?php

declare(strict_types=1);

namespace SCS\Request;

use Symfony\Component\Validator\Constraints as Assert;

class DeclareAbsenceRequest
{
    // Optional, and notify-only: it rides in the admin email and is never
    // stored, so the length cap is about keeping the email readable rather than
    // fitting a column.
    #[Assert\Length(max: 500, maxMessage: 'Keep the reason under 500 characters.')]
    public ?string $reason = null;

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto = new self();

        if ($request->get_param('reason') !== null) {
            $reason       = trim((string)$request->get_param('reason'));
            $dto->reason = $reason === '' ? null : $reason;
        }

        return $dto;
    }
}
