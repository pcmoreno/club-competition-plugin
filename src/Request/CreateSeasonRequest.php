<?php

declare(strict_types=1);

namespace SCS\Request;

use SCS\Entity\Enum\PairingSystem;
use Symfony\Component\Validator\Constraints as Assert;

class CreateSeasonRequest
{
    #[Assert\NotBlank(message: 'Name is required.')]
    public string $name = '';

    public ?string $location = null;

    #[Assert\Date(message: 'Start date must be in YYYY-MM-DD format.')]
    public ?string $start_date = null;

    #[Assert\Date(message: 'End date must be in YYYY-MM-DD format.')]
    public ?string $end_date = null;

    #[Assert\Choice(callback: [self::class, 'pairingSystemChoices'], message: 'Pairing system is not valid.')]
    public string $pairing_system = PairingSystem::Keizer->value;

    /** @var list<string> */
    public array $categories = [];

    /** @return list<string> */
    public static function pairingSystemChoices(): array
    {
        return array_column(PairingSystem::cases(), 'value');
    }

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto       = new self();
        $dto->name = trim((string)$request->get_param('name'));

        if ($request->get_param('location') !== null) {
            $dto->location = (string)$request->get_param('location');
        }
        if ($request->get_param('start_date') !== null) {
            $dto->start_date = (string)$request->get_param('start_date');
        }
        if ($request->get_param('end_date') !== null) {
            $dto->end_date = (string)$request->get_param('end_date');
        }
        if ($request->get_param('pairing_system') !== null) {
            $dto->pairing_system = (string)$request->get_param('pairing_system');
        }
        if ($request->get_param('categories') !== null) {
            $dto->categories = array_values((array)$request->get_param('categories'));
        }

        return $dto;
    }
}
