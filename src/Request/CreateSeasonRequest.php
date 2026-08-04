<?php

declare(strict_types=1);

namespace SCS\Request;

use SCS\Entity\Enum\PairingSystem;
use SCS\Entity\Enum\TimeControl;
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

    // Defaults to Manual: it's the only system with no unimplemented moving
    // parts, so an untouched form always produces a season that can be run.
    #[Assert\Choice(callback: [self::class, 'pairingSystemChoices'], message: 'Pairing system is not valid.')]
    public string $pairing_system = PairingSystem::Manual->value;

    // The tempo the tournament is played at; its games inherit it.
    #[Assert\Choice(callback: [self::class, 'timeControlChoices'], message: 'Time control is not valid.')]
    public string $time_control = TimeControl::Classical->value;

    /** @var list<string> */
    public array $categories = [];

    // The admins to notify about this tournament, honoured as given. Null means
    // the field was never sent, and the controller falls back to the creating
    // admin — a caller that omits contacts entirely still gets a sane one.
    /** @var list<int>|null */
    public ?array $contact_admin_ids = null;

    /** @return list<string> */
    public static function pairingSystemChoices(): array
    {
        return PairingSystem::implementedValues();
    }

    /** @return list<string> */
    public static function timeControlChoices(): array
    {
        return array_column(TimeControl::cases(), 'value');
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
        if ($request->get_param('time_control') !== null) {
            $dto->time_control = (string)$request->get_param('time_control');
        }
        if ($request->get_param('categories') !== null) {
            $dto->categories = array_values((array)$request->get_param('categories'));
        }
        if ($request->get_param('contact_admin_ids') !== null) {
            $dto->contact_admin_ids = array_map('intval', array_values((array)$request->get_param('contact_admin_ids')));
        }

        return $dto;
    }
}
