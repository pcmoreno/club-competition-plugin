<?php

declare(strict_types=1);

namespace SCS\Request;

use SCS\Entity\Enum\Gender;
use Symfony\Component\Validator\Constraints as Assert;

class UpdatePlayerRequest
{
    public ?string $name = null;
    public ?string $knsb_id = null;

    #[Assert\PositiveOrZero(message: 'KNSB Elo must be zero or positive.')]
    public ?int $knsb_elo = null;

    #[Assert\Choice(callback: [self::class, 'genderChoices'], message: 'Gender is not valid.')]
    public ?string $gender = null;

    #[Assert\Date(message: 'Date of birth must be in YYYY-MM-DD format.')]
    public ?string $date_of_birth = null;

    public ?bool $active = null;

    /** @return list<string> */
    public static function genderChoices(): array
    {
        return array_column(Gender::cases(), 'value');
    }

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto = new self();

        if ($request->get_param('name') !== null) {
            $dto->name = trim((string)$request->get_param('name'));
        }
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
        if ($request->get_param('active') !== null) {
            $dto->active = (bool)$request->get_param('active');
        }

        return $dto;
    }

    /** @return array<string, mixed> */
    public function toUpdateData(): array
    {
        $data = [];
        if ($this->name !== null) {
            $data['name'] = $this->name;
        }
        if ($this->knsb_id !== null) {
            $data['knsb_id'] = $this->knsb_id;
        }
        if ($this->knsb_elo !== null) {
            $data['knsb_elo'] = $this->knsb_elo;
        }
        if ($this->gender !== null) {
            $data['gender'] = $this->gender;
        }
        if ($this->date_of_birth !== null) {
            $data['date_of_birth'] = $this->date_of_birth;
        }
        if ($this->active !== null) {
            $data['active'] = (int)$this->active;
        }

        return $data;
    }
}
