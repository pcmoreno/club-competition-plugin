<?php

declare(strict_types=1);

namespace SCS\Controller;

use SCS\Engine\SettingsResolver;
use SCS\Entity\Enum\AttendanceStatus;
use SCS\Entity\Enum\PairingSystem;
use SCS\Entity\Enum\RoundStatus;
use SCS\Entity\Enum\SeasonStatus;
use SCS\Entity\Enum\TimeControl;
use SCS\Entity\Round;
use SCS\Entity\Season;
use SCS\Exception\ConflictException;
use SCS\Exception\NotFoundException;
use SCS\Exception\ValidationException;
use SCS\Repository\AdminRepository;
use SCS\Repository\AttendanceRepository;
use SCS\Repository\GameRepository;
use SCS\Repository\PlayerRepository;
use SCS\Repository\RoundRepository;
use SCS\Repository\SeasonContactRepository;
use SCS\Repository\SeasonPlayerRepository;
use SCS\Repository\SeasonRepository;
use SCS\Repository\StandingsSnapshotRepository;
use SCS\Request\AssignCategoriesRequest;
use SCS\Request\BulkPlayerIdsRequest;
use SCS\Request\CreateSeasonRequest;
use SCS\Request\EnrollPlayerRequest;
use SCS\Request\SetDefaultAbsenceRequest;
use SCS\Request\UpdateSeasonRequest;
use SCS\Services\AuthContextService;
use SCS\Services\PlayerDisplayService;
use SCS\Services\PlayerTournamentService;
use SCS\Services\SeasonContactService;
use SCS\Services\SerializerService;
use SCS\Services\SettingsValidator;
use SCS\Services\TransactionManager;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class SeasonController extends RestController
{
    public function __construct(
        ValidatorInterface $validator,
        private readonly SeasonRepository $seasonRepository,
        private readonly SeasonPlayerRepository $seasonPlayerRepository,
        private readonly PlayerRepository $playerRepository,
        private readonly PlayerDisplayService $playerDisplay,
        private readonly StandingsSnapshotRepository $standingsSnapshotRepository,
        private readonly RoundRepository $roundRepository,
        private readonly PlayerTournamentService $playerTournament,
        private readonly SerializerService $serializer,
        private readonly SettingsValidator $settingsValidator,
        private readonly SettingsResolver $settingsResolver,
        private readonly GameRepository $gameRepository,
        private readonly AttendanceRepository $attendanceRepository,
        private readonly SeasonContactRepository $seasonContactRepository,
        private readonly SeasonContactService $seasonContacts,
        private readonly AdminRepository $adminRepository,
        private readonly AuthContextService $authContext,
        private readonly TransactionManager $transactions,
    ) {
        parent::__construct($validator);
    }

    public function index(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () {
            $seasons = $this->seasonRepository->findAll();
            $counts  = $this->seasonPlayerRepository->countBySeason();

            return $this->ok(array_map(
                fn ($s) => $this->serializer->serialize($s) + ['player_count' => $counts[$s->id] ?? 0],
                $seasons
            ));
        });
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $season = $this->seasonRepository->findById((int)$request->get_param('id'));
            if ($season === null) {
                throw new NotFoundException('Season not found.');
            }

            // Display-ready enrolled players (name + category + elo), resolved
            // server-side so the roster renders without a separate fetch.
            $players = array_values($this->playerDisplay->mapForSeason($season->id));

            return $this->ok([
                'season'  => $this->serializer->serialize($season),
                'players' => $players,
            ]);
        });
    }

    public function standings(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $season = $this->seasonRepository->findById((int)$request->get_param('id'));
            if ($season === null) {
                throw new NotFoundException('Season not found.');
            }

            // Standings as of a specific round (?round=ID) — used by the round
            // history "standings after round N" panel — or, by default, the
            // latest completed round's snapshot. Each row is enriched with the
            // player's display info.
            $roundParam = $request->get_param('round');
            $snapshots  = $roundParam !== null
                ? $this->standingsSnapshotRepository->findByRoundForSeason((int)$roundParam, $season->id)
                : $this->standingsSnapshotRepository->findLatestForSeason($season->id);
            $display = $this->playerDisplay->mapForSeason($season->id);

            // Movers: each player's rank change vs the previous snapshot-bearing
            // round. rank_delta > 0 means moved up (rank number got smaller);
            // null means no prior snapshot (first round, or a new entrant).
            $previousRank = [];
            if ($snapshots !== []) {
                $previousRoundId = $this->standingsSnapshotRepository
                    ->findPreviousRoundId($season->id, $snapshots[0]->round_id);
                if ($previousRoundId !== null) {
                    foreach ($this->standingsSnapshotRepository->findByRound($previousRoundId) as $p) {
                        $previousRank[$p->season_player_id] = $p->rank;
                    }
                }
            }

            // The metric the season ranks by (StandingsMetric value, e.g. 'points'
            // or 'sonneborn_berger'); each row exposes its value as rank_score so
            // callers don't need to know which column to read.
            $rankByKey = $this->settingsResolver->scoring($season)->getSettings()['rankBy'] ?? null;

            $standings = array_map(function ($s) use ($display, $previousRank, $rankByKey) {
                $d = $display[$s->season_player_id] ?? null;

                $rankScore = $s->keizer_score !== null
                    ? (float)$s->keizer_score
                    : ($rankByKey !== null && isset($s->scores[$rankByKey])
                        ? (float)$s->scores[$rankByKey]
                        : $s->classical_points);

                return [
                    'rank'             => $s->rank,
                    'season_player_id' => $s->season_player_id,
                    'player_id'        => $d['player_id'] ?? null,
                    'name'             => $d['name'] ?? null,
                    'category'         => $d['category'] ?? null,
                    'elo'              => $d['elo'] ?? null,
                    'keizer_score'     => $s->keizer_score,
                    'classical_points' => $s->classical_points,
                    'rank_score'       => $rankScore,
                    'wins'             => $s->wins,
                    'draws'            => $s->draws,
                    'losses'           => $s->losses,
                    'games'            => $s->games,
                    'byes'             => $s->byes,
                    'color_balance'    => $s->color_balance,
                    'tpr'              => $s->tpr,
                    'rank_delta'       => isset($previousRank[$s->season_player_id])
                        ? $previousRank[$s->season_player_id] - $s->rank
                        : null,
                ];
            }, $snapshots);

            return $this->ok([
                'season'           => $this->serializer->serialize($season),
                'completed_rounds' => $this->roundRepository->countCompletedBySeason($season->id),
                'rank_by'          => $rankByKey,
                'standings'        => $standings,
            ]);
        });
    }

    /**
     * One player's whole run through this season: every game (opponent, colour,
     * own-POV result), byes, the per-round position series, and the headline
     * rank/TPR. The viewer derives W/D/L, streaks, per-category splits and
     * best-win/worst-loss from the games list.
     */
    public function playerDetail(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            return $this->ok($this->playerTournament->detail(
                (int)$request->get_param('id'),
                (int)$request->get_param('player_id'),
            ));
        });
    }

    public function store(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $input = CreateSeasonRequest::fromRequest($request);
            $this->validate($input);

            $season = $this->seasonRepository->create(
                name:           $input->name,
                location:       $input->location,
                start_date:     $input->start_date,
                end_date:       $input->end_date,
                pairing_system: PairingSystem::from($input->pairing_system),
                categories:     $input->categories,
                time_control:   TimeControl::from($input->time_control),
                is_team:        $input->is_team,
            );

            // The creating admin is the tournament's first contact — but only as
            // a default, so a list that was actually submitted is taken as it
            // stands. Forcing them in on top would contradict the form, which
            // pre-selects them and lets them take themselves off again.
            $creatorId = $this->authContext->currentClaims()['sub'] ?? null;
            $this->seasonContacts->replace(
                $season->id,
                $input->contact_admin_ids ?? ($creatorId !== null ? [$creatorId] : []),
            );

            return $this->created($this->serializer->serialize($season, SerializerService::GROUP_ADMIN));
        });
    }

    public function update(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $season = $this->seasonRepository->findById((int)$request->get_param('id'));
            if ($season === null) {
                throw new NotFoundException('Season not found.');
            }

            $input = UpdateSeasonRequest::fromRequest($request);
            $this->validate($input);

            $data = $input->toUpdateData();

            // Closed by completing its last round, so RoundService::closeSeason's rule can't be stepped around.
            if (($data['status'] ?? null) === SeasonStatus::Completed->value) {
                throw new ConflictException('A tournament is completed by completing its final round.');
            }

            if ($season->status === SeasonStatus::Completed) {
                $this->requireDisplaySettingsOnly($input, $data !== []);
            }

            // The tempo is fixed once the tournament leaves preparation. Games
            // take it when they are paired, so changing it mid-tournament would
            // split one tournament across two tempos — and a full-schedule
            // system pairs every game up front, where it wouldn't even apply.
            if (isset($data['time_control'])
                && $data['time_control'] !== $season->time_control->value
                && $season->status !== SeasonStatus::Preparation
            ) {
                throw new ValidationException(['time_control' => 'The time control can only be changed while the tournament is in preparation.']);
            }

            // Same rule again: what kind of competition this is stops being a
            // choice once it has begun, and it decides what `categories` means.
            if (isset($data['is_team'])
                && (bool)$data['is_team'] !== $season->is_team
                && $season->status !== SeasonStatus::Preparation
            ) {
                throw new ValidationException(['is_team' => 'Team play can only be changed while the tournament is in preparation.']);
            }

            // Same rule for the start date: once it has begun, that's a fact rather than a plan.
            if (isset($data['start_date'])
                && $data['start_date'] !== $season->start_date?->format('Y-m-d')
                && $season->status !== SeasonStatus::Preparation
            ) {
                throw new ValidationException(['start_date' => 'The start date can only be changed while the tournament is in preparation.']);
            }

            $systemChanged = $this->applySettings($input, $season, $data);
            // Contacts live in their own table, so they count as a change even
            // when nothing on the season row does — saving only the contacts is
            // a normal edit, not an empty request.
            if (empty($data) && $input->contact_admin_ids === null) {
                throw new ValidationException(['fields' => 'No fields to update.']);
            }

            if ($input->contact_admin_ids !== null) {
                $this->seasonContacts->replace($season->id, $input->contact_admin_ids);
            }
            if (!empty($data)) {
                $this->seasonRepository->update($season->id, $data);
            }

            // Standing absences are system-specific in the same way pairing settings
            // are: a full-schedule system can't act on them and won't let them be
            // cleared, so a switch would strand them on the enrolment.
            if ($systemChanged) {
                $this->seasonPlayerRepository->clearDefaultAbsent($season->id);
            }

            return $this->ok($this->serializer->serialize($this->seasonRepository->findById($season->id), SerializerService::GROUP_ADMIN));
        });
    }

    // Standings columns only; the flag predates applySettings, so false means settings-only.
    private function requireDisplaySettingsOnly(UpdateSeasonRequest $input, bool $touchesSeasonRow): void
    {
        if ($touchesSeasonRow
            || $input->contact_admin_ids !== null
            || $input->pairing_settings !== null
            || $input->scoring_settings !== null
        ) {
            throw new ConflictException('This tournament is completed. Only the standings columns can still be changed.');
        }
    }

    // The roster and its categories are frozen with the rest of the record.
    private function requireOpenSeason(Season $season): void
    {
        if ($season->status === SeasonStatus::Completed) {
            throw new ConflictException('This tournament is completed and can no longer be changed.');
        }
    }

    // Delete a tournament and all its scoped data. Restricted to Preparation for
    // now, so a tournament with played rounds/standings can't be wiped by accident.
    public function destroy(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $season = $this->seasonRepository->findById((int)$request->get_param('id'));
            if ($season === null) {
                throw new NotFoundException('Season not found.');
            }
            if ($season->status !== SeasonStatus::Preparation) {
                throw new ConflictException('Only a tournament in preparation can be deleted.');
            }

            // No FK cascade, so clear child rows first (children before the
            // season) — and transactionally, because a failure part-way through
            // would leave a season whose enrolments are gone but whose rounds
            // remain: every player in the round view resolves to null, and the
            // snapshots point at season_player ids that no longer exist.
            $this->transactions->transactional(function () use ($season): void {
                $this->standingsSnapshotRepository->deleteBySeason($season->id);
                $this->gameRepository->deleteBySeason($season->id);
                $this->attendanceRepository->deleteBySeason($season->id);
                $this->seasonPlayerRepository->deleteBySeason($season->id);
                $this->roundRepository->deleteBySeason($season->id);
                $this->seasonContactRepository->deleteBySeason($season->id);
                $this->seasonRepository->delete($season->id);
            });

            return $this->ok(['deleted' => true]);
        });
    }

    /**
     * Admin read: this tournament's contacts, in the order they're stored.
     *
     * Empty means the tournament has never set one, which the mailer reads as
     * "all active admins" (SeasonContactService) — `notifies_all_admins` says
     * that outright rather than leaving the form to infer it from the empty
     * list. The selectable admins come from GET /admins.
     */
    public function contacts(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $season = $this->seasonRepository->findById((int)$request->get_param('id'));
            if ($season === null) {
                throw new NotFoundException('Season not found.');
            }

            $stored = $this->seasonContacts->storedAdminIds($season->id);

            return $this->ok([
                'contacts' => $this->serializer->serializeMany(
                    $this->adminRepository->findByIds($stored),
                    SerializerService::GROUP_ADMIN
                ),
                'notifies_all_admins' => $stored === [],
            ]);
        });
    }

    /**
     * Admin read: the season's three settings blobs, each as stored values plus
     * the field schema the form renders from. `fields` is null when that axis
     * isn't configurable for this system yet.
     */
    public function settings(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $season = $this->seasonRepository->findById((int)$request->get_param('id'));
            if ($season === null) {
                throw new NotFoundException('Season not found.');
            }

            $pairing = $this->settingsResolver->pairing($season);
            $scoring = $this->settingsResolver->scoring($season);
            $display = $this->settingsResolver->display($season);

            return $this->ok([
                'pairing_system' => $season->pairing_system->value,
                'scoring_system' => $season->pairing_system->scoringSystem()->value,
                // Scoring is frozen once a round has completed, so the form can disable it.
                'scoring_locked' => $this->roundRepository->countCompletedBySeason($season->id) > 0,
                'pairing' => [
                    'values' => $pairing?->getSettings(),
                    'fields' => $pairing?->getSettingsFields(),
                ],
                'scoring' => [
                    'values' => $scoring->getSettings(),
                    'fields' => $scoring->getSettingsFields(),
                ],
                'display' => [
                    'values' => $display->getSettings(),
                    'fields' => $display->getSettingsFields(),
                ],
            ]);
        });
    }

    /**
     * Validate + normalise the three settings blobs into the update payload.
     * Changing the pairing system resets pairing settings to the new defaults
     * (the frontend confirms via a modal), and resets scoring only when the new
     * system scores differently; scoring settings lock after the first completed
     * round; display settings are always editable.
     *
     * Returns whether the pairing system changed.
     *
     * @param array<string,mixed> $data
     */
    private function applySettings(UpdateSeasonRequest $input, Season $season, array &$data): bool
    {
        $newSystem     = $input->pairing_system !== null ? PairingSystem::from($input->pairing_system) : $season->pairing_system;
        $systemChanged = $newSystem !== $season->pairing_system;
        $scoringLocked = $this->roundRepository->countCompletedBySeason($season->id) > 0;

        if ($systemChanged) {
            if (!$newSystem->isImplemented()) {
                throw new ValidationException(['pairing_system' => 'That pairing system is not implemented yet.']);
            }

            // The pairing system is fixed once the tournament leaves preparation;
            // its games/scoring are already keyed to that system.
            if ($season->status !== SeasonStatus::Preparation) {
                throw new ValidationException(['pairing_system' => 'The pairing system can only be changed while the tournament is in preparation.']);
            }

            // Pairing settings are system-specific, so they never survive a switch.
            $data['pairing_settings'] = null;

            // Wiping scoring is itself a scoring change, so it obeys the same lock.
            if ($newSystem->scoringSystem() !== $season->pairing_system->scoringSystem()) {
                if ($scoringLocked) {
                    throw new ValidationException(['pairing_system' => 'This pairing system scores differently and cannot be selected after the first completed round.']);
                }
                $data['scoring_settings'] = null;
            }
        } elseif ($input->pairing_settings !== null) {
            $data['pairing_settings'] = json_encode($this->settingsValidator->validatePairing($newSystem, $input->pairing_settings));
        }

        if ($input->scoring_settings !== null) {
            if ($scoringLocked) {
                throw new ValidationException(['scoring_settings' => 'Scoring settings are locked after the first completed round.']);
            }
            // Whether this system has scoring settings at all is the validator's
            // call now — it resolves the class that will parse the blob.
            $data['scoring_settings'] = json_encode($this->settingsValidator->validateScoring($newSystem, $input->scoring_settings));
        }

        if ($input->display_settings !== null) {
            $data['display_settings'] = json_encode($this->settingsValidator->validateDisplay($input->display_settings));
        }

        return $systemChanged;
    }

    public function enrollPlayer(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $season = $this->seasonRepository->findById((int)$request->get_param('id'));
            if ($season === null) {
                throw new NotFoundException('Season not found.');
            }

            $this->requireOpenSeason($season);

            $input = EnrollPlayerRequest::fromRequest($request);
            $this->validate($input);

            // Category is optional on enrol; when given it must match the season's set.
            if ($input->category !== null) {
                if ($season->categories === []) {
                    throw new ValidationException([
                        'category' => 'This season has no categories; leave the category empty.',
                    ]);
                }
                if (!in_array($input->category, $season->categories, true)) {
                    throw new ValidationException([
                        'category' => sprintf('Category must be one of: %s.', implode(', ', $season->categories)),
                    ]);
                }
            }

            $player = $this->playerRepository->findById($input->player_id);
            if ($player === null) {
                throw new NotFoundException('Player not found.');
            }

            $existing = $this->seasonPlayerRepository->findBySeasonAndPlayer($season->id, $input->player_id);
            if ($existing !== null) {
                throw new ConflictException('Player is already enrolled in this season.');
            }

            $eloRating = $input->elo_rating ?? $player->knsb_elo ?? 0;

            $seasonPlayer = $this->seasonPlayerRepository->create($season->id, $input->player_id, $input->category, $eloRating);

            return $this->created($this->serializer->serialize($seasonPlayer));
        });
    }

    // Assign/reassign/clear an enrolled player's category. A null (or empty)
    // category unassigns; a given category must be one the season defines.
    public function setPlayerCategory(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $season = $this->seasonRepository->findById((int)$request->get_param('id'));
            if ($season === null) {
                throw new NotFoundException('Season not found.');
            }

            $this->requireOpenSeason($season);

            $seasonPlayer = $this->seasonPlayerRepository->findBySeasonAndPlayer(
                $season->id,
                (int)$request->get_param('player_id')
            );
            if ($seasonPlayer === null) {
                throw new NotFoundException('Player is not enrolled in this season.');
            }

            $raw      = $request->get_param('category');
            $category = ($raw === null || $raw === '') ? null : (string)$raw;

            if ($category !== null) {
                if ($season->categories === []) {
                    throw new ValidationException(['category' => 'This season has no categories.']);
                }
                if (!in_array($category, $season->categories, true)) {
                    throw new ValidationException([
                        'category' => sprintf('Category must be one of: %s.', implode(', ', $season->categories)),
                    ]);
                }
            }

            $this->seasonPlayerRepository->update($seasonPlayer->id, ['category' => $category]);
            $updated = $this->seasonPlayerRepository->findById($seasonPlayer->id);

            return $this->ok($this->serializer->serialize($updated ?? $seasonPlayer));
        });
    }

    public function removePlayer(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $season = $this->seasonRepository->findById((int)$request->get_param('id'));
            if ($season === null) {
                throw new NotFoundException('Season not found.');
            }

            if ($season->status !== SeasonStatus::Preparation) {
                throw new ConflictException('Players can only be removed while the tournament is in preparation.');
            }

            $seasonPlayer = $this->seasonPlayerRepository->findBySeasonAndPlayer(
                $season->id,
                (int)$request->get_param('player_id')
            );

            if ($seasonPlayer === null) {
                throw new NotFoundException('Player is not enrolled in this season.');
            }

            $this->seasonPlayerRepository->delete($seasonPlayer->id);

            return $this->noContent();
        });
    }

    // Enrol many players in one atomic request (the Players tab "Add all"). Ids
    // already enrolled or not matching a player are skipped, so it's idempotent;
    // categories aren't set here (they're assigned on the Categories tab).
    public function enrollPlayers(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $season = $this->seasonRepository->findById((int)$request->get_param('id'));
            if ($season === null) {
                throw new NotFoundException('Season not found.');
            }

            $this->requireOpenSeason($season);

            $input = BulkPlayerIdsRequest::fromRequest($request);
            $this->validate($input);

            $enrolled = [];
            foreach ($this->seasonPlayerRepository->findBySeason($season->id) as $sp) {
                $enrolled[$sp->player_id] = true;
            }

            $entries = [];
            foreach (array_unique($input->player_ids ?? []) as $playerId) {
                if (isset($enrolled[$playerId])) {
                    continue;
                }
                $player = $this->playerRepository->findById((int)$playerId);
                if ($player === null) {
                    continue;
                }
                $entries[] = [
                    'player_id'  => (int)$playerId,
                    'category'   => null,
                    'elo_rating' => $player->knsb_elo ?? 0,
                ];
            }

            $this->seasonPlayerRepository->createMany($season->id, $entries);

            $players = $this->seasonPlayerRepository->findBySeason($season->id);

            return $this->ok(array_map($this->serializer->serialize(...), $players));
        });
    }

    // Remove many players in one atomic request (the Players tab "Remove all").
    // Gated to preparation for the same reason as the single remove: a played
    // player's games/attendance/snapshots would be orphaned.
    public function removePlayers(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $season = $this->seasonRepository->findById((int)$request->get_param('id'));
            if ($season === null) {
                throw new NotFoundException('Season not found.');
            }

            if ($season->status !== SeasonStatus::Preparation) {
                throw new ConflictException('Players can only be removed while the tournament is in preparation.');
            }

            $input = BulkPlayerIdsRequest::fromRequest($request);
            $this->validate($input);

            $this->seasonPlayerRepository->deleteBySeasonAndPlayers($season->id, $input->player_ids ?? []);

            return $this->noContent();
        });
    }

    // Apply many category assignments in one atomic request (Auto Fill). Every
    // category is validated against the season's set up front, so either all
    // land or the whole batch is rejected with field errors.
    public function assignCategories(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $season = $this->seasonRepository->findById((int)$request->get_param('id'));
            if ($season === null) {
                throw new NotFoundException('Season not found.');
            }

            $this->requireOpenSeason($season);

            $input = AssignCategoriesRequest::fromRequest($request);
            $this->validate($input);

            $enrolled = [];
            foreach ($this->seasonPlayerRepository->findBySeason($season->id) as $sp) {
                $enrolled[$sp->player_id] = $sp;
            }

            $errors  = [];
            $updates = [];
            foreach ($input->assignments ?? [] as $i => $assignment) {
                $playerId = (int)$assignment['player_id'];
                $raw      = $assignment['category'] ?? null;
                $category = ($raw === null || $raw === '') ? null : (string)$raw;

                if ($category !== null && !in_array($category, $season->categories, true)) {
                    $errors["assignments.$i.category"] = $season->categories === []
                        ? 'This season has no categories.'
                        : sprintf('Category must be one of: %s.', implode(', ', $season->categories));

                    continue;
                }

                if (!isset($enrolled[$playerId])) {
                    continue;
                }

                $updates[] = [ 'id' => $enrolled[$playerId]->id, 'category' => $category ];
            }

            if ($errors !== []) {
                throw new ValidationException($errors);
            }

            $this->seasonPlayerRepository->updateCategories($updates);

            $players = $this->seasonPlayerRepository->findBySeason($season->id);

            return $this->ok(array_map($this->serializer->serialize(...), $players));
        });
    }

    // The Absences tab: the roster split by standing absence, plus the absences
    // already recorded for the round about to be played.
    public function absences(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $season = $this->seasonRepository->findById((int)$request->get_param('id'));
            if ($season === null) {
                throw new NotFoundException('Season not found.');
            }

            $display = $this->playerDisplay->mapForSeason($season->id);

            $enrolments = [];
            $flagged    = [];
            foreach ($this->seasonPlayerRepository->findBySeason($season->id) as $sp) {
                $enrolments[] = ($display[$sp->id] ?? []) + [ 'default_absent' => $sp->default_absent ];
                if ($sp->default_absent) {
                    $flagged[$sp->id] = true;
                }
            }

            $round = $this->latestOpenRound($season->id);

            // Standing absences are the upper boxes' subject, so listing them here would drown the news.
            $declared = [];
            if ($round !== null) {
                foreach ($this->attendanceRepository->findByRound($round->id) as $row) {
                    if ($row->status !== AttendanceStatus::Absent || isset($flagged[$row->season_player_id])) {
                        continue;
                    }

                    $declared[] = [
                        'season_player_id' => $row->season_player_id,
                        'name'             => $display[$row->season_player_id]['name'] ?? null,
                        'bye_type'         => $row->bye_type?->value,
                    ];
                }
            }

            usort($declared, static fn (array $a, array $b): int => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));

            return $this->ok([
                'enrolments' => $enrolments,
                'round'      => $round === null ? null : [
                    'id'     => $round->id,
                    'number' => $round->round_number,
                    'date'   => $round->date?->format('Y-m-d'),
                    'status' => $round->status->value,
                ],
                'declared'   => $declared,
            ]);
        });
    }

    // Move enrolments between default-present and default-absent (the Absences tab transfer list).
    public function setDefaultAbsence(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $season = $this->seasonRepository->findById((int)$request->get_param('id'));
            if ($season === null) {
                throw new NotFoundException('Season not found.');
            }

            $this->requireOpenSeason($season);

            // A full schedule pairs every round up front, so it never runs the round-creation trigger.
            if ($season->pairing_system->cadence() === 'full') {
                throw new ConflictException('This tournament lays out its whole schedule at once, so a standing absence has nothing to apply to.');
            }

            $input = SetDefaultAbsenceRequest::fromRequest($request);
            $this->validate($input);

            $enrolled = [];
            foreach ($this->seasonPlayerRepository->findBySeason($season->id) as $sp) {
                $enrolled[$sp->player_id] = $sp;
            }

            $ids = [];
            foreach (array_unique($input->player_ids ?? []) as $playerId) {
                if (isset($enrolled[$playerId])) {
                    $ids[] = $enrolled[$playerId]->id;
                }
            }

            $this->seasonPlayerRepository->updateDefaultAbsent($season->id, $ids, (bool)$input->default_absent);

            $players = $this->seasonPlayerRepository->findBySeason($season->id);

            return $this->ok(array_map($this->serializer->serialize(...), $players));
        });
    }

    // The round about to be played: the highest-numbered one that isn't complete.
    private function latestOpenRound(int $seasonId): ?Round
    {
        $latest = null;
        foreach ($this->roundRepository->findBySeason($seasonId) as $round) {
            if ($round->status === RoundStatus::Complete) {
                continue;
            }
            if ($latest === null || $round->round_number > $latest->round_number) {
                $latest = $round;
            }
        }

        return $latest;
    }
}
