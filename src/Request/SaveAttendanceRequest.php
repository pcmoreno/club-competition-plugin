<?php

declare(strict_types=1);

namespace SCS\Request;

use SCS\Entity\Enum\AttendanceStatus;
use SCS\Entity\Enum\ByeType;
use Symfony\Component\Validator\Constraints as Assert;

class SaveAttendanceRequest
{
    #[Assert\NotNull(message: 'attendance is required.')]
    #[Assert\Type(type: 'array', message: 'attendance must be an array.')]
    #[Assert\All([
        new Assert\Collection(
            fields: [
                'season_player_id' => [
                    new Assert\NotBlank(message: 'season_player_id is required.'),
                    new Assert\Positive(message: 'season_player_id must be a positive integer.'),
                ],
                'status' => [
                    new Assert\NotBlank(message: 'status is required.'),
                    new Assert\Choice(callback: [self::class, 'statusChoices'], message: 'status is not valid.'),
                ],
                'bye_type' => new Assert\Optional([
                    new Assert\Choice(callback: [self::class, 'byeTypeChoices'], message: 'bye_type is not valid.'),
                ]),
            ],
            allowMissingFields: false,
            allowExtraFields: false,
        ),
    ])]
    public ?array $attendance = null;

    /** @return list<string> */
    public static function statusChoices(): array
    {
        return array_column(AttendanceStatus::cases(), 'value');
    }

    /** @return list<string> */
    public static function byeTypeChoices(): array
    {
        return array_column(ByeType::cases(), 'value');
    }

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto             = new self();
        $entries         = $request->get_param('attendance');
        $dto->attendance = is_array($entries) ? $entries : null;

        return $dto;
    }
}
