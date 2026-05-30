<?php

declare(strict_types=1);

namespace SCS\Request;

use SCS\Entity\Enum\Gender;
use Symfony\Component\Validator\Constraints as Assert;

class CreatePlayerRequest
{
    #[Assert\NotBlank(message: 'Name is required.')]
    public string $name = '';

    public ?string $knsb_id = null;

    #[Assert\PositiveOrZero(message: 'KNSB Elo must be zero or positive.')]
    public ?int $knsb_elo = null;

    #[Assert\Choice(callback: [self::class, 'genderChoices'], message: 'Gender is not valid.')]
    public ?string $gender = null;

    #[Assert\Date(message: 'Date of birth must be in YYYY-MM-DD format.')]
    public ?string $date_of_birth = null;

    /** @return list<string> */
    public static function genderChoices(): array
    {
        return array_column(Gender::cases(), 'value');
    }

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto       = new self();
        $dto->name = trim((string)$request->get_param('name'));

        if ($request->get_param('knsb_id') !== null) {
            $dto->knsb_id = (string)$request->get_param('knsb_id');
        }
        if ($request->get_param('knsb_elo') !== null) {
            $dto->knsb_elo = (int)$request->get_param('knsb_elo');
        }
        if ($request->get_param('gender') !== null) {
            $dto->gender = (string)$request->get_param('gender');
        }
        if ($request->get_param('date_of_birth') !== null) {
            $dto->date_of_birth = (string)$request->get_param('date_of_birth');
        }

        return $dto;
    }
}
