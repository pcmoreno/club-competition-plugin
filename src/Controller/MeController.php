<?php

declare(strict_types=1);

namespace SCS\Controller;

use SCS\Exception\NotFoundException;
use SCS\Repository\PlayerRepository;
use SCS\Services\AuthContextService;
use SCS\Services\PlayerHomeService;
use SCS\Services\SerializerService;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Everything under /me — the signed-in account's own view of the competition,
 * always derived from the JWT rather than an id in the request. There is no
 * /me/{id}: the only player this can ever answer for is the caller.
 */
class MeController extends RestController
{
    public function __construct(
        ValidatorInterface $validator,
        private readonly AuthContextService $authContext,
        private readonly PlayerRepository $playerRepository,
        private readonly PlayerHomeService $playerHomeService,
        private readonly SerializerService $serializer,
    ) {
        parent::__construct($validator);
    }

    /**
     * The member home page: next pairing per running tournament, the
     * tournaments they're in now, and the ones they've finished.
     */
    public function home(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () {
            $player = $this->currentPlayer();

            return $this->ok([
                'player' => $this->serializer->serialize($player),
            ] + $this->playerHomeService->home($player->id));
        });
    }

    /**
     * The player behind the signed-in account. Admins reach these routes too
     * (the member gate admits both roles) but have no player record, so this is
     * a 404 for them — the frontend only offers Home to accounts that have one.
     */
    private function currentPlayer(): \SCS\Entity\Player
    {
        $claims   = $this->authContext->currentClaims();
        $playerId = $claims['pid'] ?? null;

        $player = $playerId === null ? null : $this->playerRepository->findById($playerId);
        if ($player === null) {
            throw new NotFoundException('This account has no player record.');
        }

        return $player;
    }
}
