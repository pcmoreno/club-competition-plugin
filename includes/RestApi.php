<?php

declare(strict_types=1);

namespace SCS\includes;

use SCS\Controller\AuthController;
use SCS\Entity\Enum\Role;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Security\Csrf\CsrfToken;

class RestApi
{
    public static function register(ContainerBuilder $container): void
    {
        add_action('rest_api_init', function () use ($container) {
            $authContext     = $container->get('auth_context_service');
            $csrfManager     = $container->get('csrf_token_manager');
            $auth            = $container->get('auth_controller');
            $players         = $container->get('player_controller');
            $seasons         = $container->get('season_controller');
            $rounds          = $container->get('round_controller');
            $import          = $container->get('import_controller');

            // Parse the auth cookie's JWT and re-validate it against the DB
            // (account still exists, still Active, not older than a password
            // change), or null when absent/invalid/stale. Single source for
            // every role check below.
            $claims = function () use ($authContext) {
                return $authContext->currentClaims();
            };

            // Build a permission callback that requires the signed-in user to
            // hold one of $roles. CSRF (writes only) is layered on top in
            // $isAdmin — the role gate itself is shared by reads and writes.
            $requireRole = function (array $roles, string $message) use ($claims) {
                return function () use ($claims, $roles, $message) {
                    $c = $claims();
                    if (!$c || !in_array($c['role'], $roles, true)) {
                        return new \WP_Error('forbidden', $message, ['status' => 403]);
                    }

                    return true;
                };
            };

            // Any signed-in user (member or admin). No CSRF check — applied only
            // to GET reads. Note this gates the standalone roster and player
            // detail; pairings/results stay public, so player names and Elo are
            // still reachable through the public round endpoints.
            $isMember = $requireRole([Role::Member->value, Role::Admin->value], 'Member access required.');

            // Admin-only GET reads: same role gate as $isAdmin but without the
            // CSRF check, which only applies to writes — reads don't mutate
            // state and the frontend doesn't send the CSRF header on GETs.
            $isAdminRead = $requireRole([Role::Admin->value], 'Admin access required.');

            // CSRF header check alone, no role requirement — usable on routes
            // an anonymous visitor can hit (login, logout). GET /auth/csrf-token
            // is itself public, so the frontend can fetch a token before the
            // user is signed in.
            $requiresCsrf = function (\WP_REST_Request $request) use ($csrfManager) {
                $csrfHeader = $request->get_header('X-SCS-CSRF-Token');
                if (!$csrfHeader || !$csrfManager->isTokenValid(new CsrfToken(AuthController::CSRF_TOKEN_ID, $csrfHeader))) {
                    return new \WP_Error('forbidden', 'Invalid CSRF token.', ['status' => 403]);
                }

                return true;
            };

            // Admin write endpoints: the admin role gate plus a valid CSRF
            // header. Reuses $isAdminRead for the role half so the cookie/role
            // logic lives in exactly one place.
            $isAdmin = function (\WP_REST_Request $request) use ($isAdminRead, $requiresCsrf) {
                $allowed = $isAdminRead();
                if ($allowed !== true) {
                    return $allowed;
                }

                return $requiresCsrf($request);
            };

            // Member (or admin) write endpoints: any signed-in user plus a valid
            // CSRF header. Same composition as $isAdmin but the wider role gate —
            // used by self-service writes like changing your own password.
            $isMemberWrite = function (\WP_REST_Request $request) use ($isMember, $requiresCsrf) {
                $allowed = $isMember();
                if ($allowed !== true) {
                    return $allowed;
                }

                return $requiresCsrf($request);
            };

            // ── Auth ──────────────────────────────────────────────────────────
            register_rest_route('scs/v1', '/auth/login', [
                'methods'             => 'POST',
                'callback'            => [$auth, 'login'],
                'permission_callback' => $requiresCsrf,
            ]);

            register_rest_route('scs/v1', '/auth/logout', [
                'methods'             => 'POST',
                'callback'            => [$auth, 'logout'],
                'permission_callback' => $requiresCsrf,
            ]);

            register_rest_route('scs/v1', '/auth/accept-invite', [
                'methods'             => 'POST',
                'callback'            => [$auth, 'acceptInvite'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('scs/v1', '/auth/invite-status', [
                'methods'             => 'GET',
                'callback'            => [$auth, 'inviteStatus'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('scs/v1', '/auth/forgot-password', [
                'methods'             => 'POST',
                'callback'            => [$auth, 'forgotPassword'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('scs/v1', '/auth/reset-password', [
                'methods'             => 'POST',
                'callback'            => [$auth, 'resetPassword'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('scs/v1', '/auth/csrf-token', [
                'methods'             => 'GET',
                'callback'            => [$auth, 'csrfToken'],
                'permission_callback' => '__return_true',
            ]);

            // First-admin bootstrap: public by necessity (there's no admin yet
            // to authenticate the request). NOT an $isAdmin write route — the
            // controller enforces the zero-admins invariant instead, so both
            // endpoints go inert the moment any admin exists. Break-glass path
            // for hosts where WP-CLI's `wp scs create-admin` isn't reachable.
            register_rest_route('scs/v1', '/auth/bootstrap-status', [
                'methods'             => 'GET',
                'callback'            => [$auth, 'bootstrapStatus'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('scs/v1', '/auth/bootstrap-admin', [
                'methods'             => 'POST',
                'callback'            => [$auth, 'bootstrapAdmin'],
                'permission_callback' => '__return_true',
            ]);

            // The signed-in user's own account data for the Account page.
            register_rest_route('scs/v1', '/auth/me', [
                'methods'             => 'GET',
                'callback'            => [$auth, 'me'],
                'permission_callback' => $isMember,
            ]);

            // Self-service password change (member or admin).
            register_rest_route('scs/v1', '/auth/change-password', [
                'methods'             => 'POST',
                'callback'            => [$auth, 'changePassword'],
                'permission_callback' => $isMemberWrite,
            ]);

            // ── Players ───────────────────────────────────────────────────────
            register_rest_route('scs/v1', '/players', [
                [
                    'methods'             => 'GET',
                    'callback'            => [$players, 'index'],
                    'permission_callback' => $isAdminRead,
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [$players, 'store'],
                    'permission_callback' => $isAdmin,
                ],
            ]);

            register_rest_route('scs/v1', '/players/(?P<id>\d+)', [
                [
                    'methods'             => 'GET',
                    'callback'            => [$players, 'show'],
                    'permission_callback' => $isMember,
                ],
                [
                    'methods'             => 'PATCH',
                    'callback'            => [$players, 'update'],
                    'permission_callback' => $isAdmin,
                ],
                [
                    'methods'             => 'DELETE',
                    'callback'            => [$players, 'destroy'],
                    'permission_callback' => $isAdmin,
                ],
            ]);

            // Merge one player into another (admin): the source player's history
            // moves to {id} and the source is deleted. Body: { source_id }.
            register_rest_route('scs/v1', '/players/(?P<id>\d+)/merge', [
                [
                    'methods'             => 'POST',
                    'callback'            => [$players, 'merge'],
                    'permission_callback' => $isAdmin,
                ],
            ]);

            // Apply the player's rating from the last-fetched KNSB list (admin).
            register_rest_route('scs/v1', '/players/(?P<id>\d+)/knsb-rating', [
                [
                    'methods'             => 'POST',
                    'callback'            => [$players, 'applyKnsbRating'],
                    'permission_callback' => $isAdmin,
                ],
            ]);

            // The seasons/tournaments a player is enrolled in (admin, PII-adjacent).
            register_rest_route('scs/v1', '/players/(?P<id>\d+)/tournaments', [
                [
                    'methods'             => 'GET',
                    'callback'            => [$players, 'tournaments'],
                    'permission_callback' => $isAdminRead,
                ],
            ]);

            // Invite a player to become a member (admin): create the account + email.
            register_rest_route('scs/v1', '/players/(?P<id>\d+)/invite', [
                [
                    'methods'             => 'POST',
                    'callback'            => [$players, 'invite'],
                    'permission_callback' => $isAdmin,
                ],
            ]);

            // Revoke a player's member account (admin): disable login immediately.
            register_rest_route('scs/v1', '/players/(?P<id>\d+)/revoke', [
                [
                    'methods'             => 'POST',
                    'callback'            => [$players, 'revoke'],
                    'permission_callback' => $isAdmin,
                ],
            ]);

            // ── Seasons ───────────────────────────────────────────────────────
            register_rest_route('scs/v1', '/seasons', [
                [
                    'methods'             => 'GET',
                    'callback'            => [$seasons, 'index'],
                    'permission_callback' => '__return_true',
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [$seasons, 'store'],
                    'permission_callback' => $isAdmin,
                ],
            ]);

            register_rest_route('scs/v1', '/seasons/(?P<id>\d+)', [
                [
                    'methods'             => 'GET',
                    'callback'            => [$seasons, 'show'],
                    'permission_callback' => $isMember,
                ],
                [
                    'methods'             => 'PATCH',
                    'callback'            => [$seasons, 'update'],
                    'permission_callback' => $isAdmin,
                ],
            ]);

            register_rest_route('scs/v1', '/seasons/(?P<id>\d+)/standings', [
                'methods'             => 'GET',
                'callback'            => [$seasons, 'standings'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('scs/v1', '/seasons/(?P<id>\d+)/players', [
                [
                    'methods'             => 'POST',
                    'callback'            => [$seasons, 'enrollPlayer'],
                    'permission_callback' => $isAdmin,
                ],
            ]);

            register_rest_route('scs/v1', '/seasons/(?P<id>\d+)/players/(?P<player_id>\d+)', [
                [
                    'methods'             => 'GET',
                    'callback'            => [$seasons, 'playerDetail'],
                    'permission_callback' => '__return_true',
                ],
                [
                    'methods'             => 'DELETE',
                    'callback'            => [$seasons, 'removePlayer'],
                    'permission_callback' => $isAdmin,
                ],
            ]);

            // ── Rounds ────────────────────────────────────────────────────────
            register_rest_route('scs/v1', '/seasons/(?P<season_id>\d+)/rounds', [
                [
                    'methods'             => 'GET',
                    'callback'            => [$rounds, 'index'],
                    'permission_callback' => '__return_true',
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [$rounds, 'store'],
                    'permission_callback' => $isAdmin,
                ],
            ]);

            register_rest_route('scs/v1', '/rounds/(?P<id>\d+)', [
                'methods'             => 'GET',
                'callback'            => [$rounds, 'show'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('scs/v1', '/rounds/(?P<id>\d+)/status', [
                'methods'             => 'PATCH',
                'callback'            => [$rounds, 'updateStatus'],
                'permission_callback' => $isAdmin,
            ]);

            register_rest_route('scs/v1', '/rounds/(?P<id>\d+)/attendance', [
                'methods'             => 'PUT',
                'callback'            => [$rounds, 'saveAttendance'],
                'permission_callback' => $isAdmin,
            ]);

            // ── Games ─────────────────────────────────────────────────────────
            register_rest_route('scs/v1', '/games/(?P<id>\d+)/result', [
                'methods'             => 'PATCH',
                'callback'            => [$rounds, 'updateGameResult'],
                'permission_callback' => $isAdmin,
            ]);

            // ── Fixtures / import ─────────────────────────────────────────────
            // Seeds a whole season from a plugin-shipped SQL fixture. The load is
            // destructive (full-replace of competition tables), so it goes
            // through $isAdmin (role + CSRF), not just $isAdminRead.
            register_rest_route('scs/v1', '/fixtures', [
                'methods'             => 'GET',
                'callback'            => [$import, 'index'],
                'permission_callback' => $isAdminRead,
            ]);

            register_rest_route('scs/v1', '/fixtures/load', [
                'methods'             => 'POST',
                'callback'            => [$import, 'load'],
                'permission_callback' => $isAdmin,
            ]);
        });
    }
}
