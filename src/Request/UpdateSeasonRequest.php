<?php

declare(strict_types=1);

namespace SCS\Request;

use SCS\Entity\Enum\PairingSystem;
use SCS\Entity\Enum\SeasonStatus;
use Symfony\Component\Validator\Constraints as Assert;

class UpdateSeasonRequest
{
    public ?string $name = null;
    public ?string $location = null;

    #[Assert\Date(message: 'Start date must be in YYYY-MM-DD format.')]
    public ?string $start_date = null;

    #[Assert\Date(message: 'End date must be in YYYY-MM-DD format.')]
    public ?string $end_date = null;

    #[Assert\Choice(callback: [self::class, 'pairingSystemChoices'], message: 'Pairing system is not valid.')]
    public ?string $pairing_system = null;

    #[Assert\Choice(callback: [self::class, 'statusChoices'], message: 'Status is not valid.')]
    public ?string $status = null;

    /** @var list<string>|null */
    public ?array $categories = null;

    /** @return list<string> */
    public static function pairingSystemChoices(): array
    {
        return array_column(PairingSystem::cases(), 'value');
    }

    /** @return list<string> */
    public static function statusChoices(): array
    {
        return array_column(SeasonStatus::cases(), 'value');
    }

    public static function fromRequest(\WP_REST_Request $request): self
    {
        $dto = new self();

        if ($request->get_param('name') !== null) {
            $dto->name = trim((string)$request->get_param('name'));
        }
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
        if ($request->get_param('status') !== null) {
            $dto->status = (string)$request->get_param('status');
        }
        if ($request->get_param('categories') !== null) {
            $dto->categories = array_values((array)$request->get_param('categories'));
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
        if ($this->location !== null) {
            $data['location'] = $this->location;
        }
        if ($this->start_date !== null) {
            $data['start_date'] = $this->start_date;
        }
        if ($this->end_date !== null) {
            $data['end_date'] = $this->end_date;
        }
        if ($this->pairing_system !== null) {
            $data['pairing_system'] = $this->pairing_system;
        }
        if ($this->status !== null) {
            $data['status'] = $this->status;
        }
        if ($this->categories !== null) {
            $data['categories'] = json_encode($this->categories);
        }

        return $data;
    }
}
