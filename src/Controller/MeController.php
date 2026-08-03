<?php

declare(strict_types=1);

namespace SCS\Controller;

use SCS\Exception\NotFoundException;
use SCS\Repository\PlayerRepository;
use SCS\Request\DeclareAbsenceRequest;
use SCS\Services\AuthContextService;
use SCS\Services\PlayerHomeService;
use SCS\Services\RoundAbsenceService;
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
        private readonly RoundAbsenceService $roundAbsenceService,
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
                'player'     => $this->serializer->serialize($player),
                'declinable' => $this->roundAbsenceService->declinableRounds($player->id),
            ] + $this->playerHomeService->home($player->id));
        });
    }

    /**
     * "I can't play this round." What happens depends on whether they're already
     * on a board: if not, the absence is recorded; if they are, it only reaches
     * the admin — see RoundAbsenceService. The reason is emailed, never stored.
     */
    public function declareAbsence(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $player = $this->currentPlayer();
            $input  = DeclareAbsenceRequest::fromRequest($request);
            $this->validate($input);

            return $this->ok($this->roundAbsenceService->declare(
                $player->id,
                (int)$request->get_param('id'),
                $input->reason,
            ));
        });
    }

    /** "I can play after all." Only while they're still unpaired. */
    public function withdrawAbsence(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $player = $this->currentPlayer();

            $this->roundAbsenceService->withdraw($player->id, (int)$request->get_param('id'));

            return $this->noContent();
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
