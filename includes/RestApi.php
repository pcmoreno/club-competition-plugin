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
            $jwtService      = $container->get('jwt_service');
            $csrfManager     = $container->get('csrf_token_manager');
            $auth            = $container->get('auth_controller');
            $players         = $container->get('player_controller');
            $seasons         = $container->get('season_controller');
            $rounds          = $container->get('round_controller');

            $isAdmin = function (\WP_REST_Request $request) use ($jwtService, $csrfManager) {
                $token  = $_COOKIE['scs_token'] ?? null;
                $claims = $token ? $jwtService->parse($token) : null;
                if (!$claims || $claims['role'] !== Role::Admin->value) {
                    return new \WP_Error('forbidden', 'Admin access required.', ['status' => 403]);
                }

                $csrfHeader = $request->get_header('X-SCS-CSRF-Token');
                if (!$csrfHeader || !$csrfManager->isTokenValid(new CsrfToken(AuthController::CSRF_TOKEN_ID, $csrfHeader))) {
                    return new \WP_Error('forbidden', 'Invalid CSRF token.', ['status' => 403]);
                }

                return true;
            };

            // Any signed-in user (member or admin). No CSRF check — applied only
            // to GET reads. Note this gates the standalone roster and player
            // detail; pairings/results stay public, so player names and Elo are
            // still reachable through the public round endpoints.
            $isMember = function () use ($jwtService) {
                $token  = $_COOKIE['scs_token'] ?? null;
                $claims = $token ? $jwtService->parse($token) : null;
                if (!$claims || !in_array($claims['role'], [Role::Member->value, Role::Admin->value], true)) {
                    return new \WP_Error('forbidden', 'Member access required.', ['status' => 403]);
                }

                return true;
            };

            // ── Auth ──────────────────────────────────────────────────────────
            register_rest_route('scs/v1', '/auth/login', [
                'methods'             => 'POST',
                'callback'            => [$auth, 'login'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('scs/v1', '/auth/logout', [
                'methods'             => 'POST',
                'callback'            => [$auth, 'logout'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('scs/v1', '/auth/accept-invite', [
                'methods'             => 'POST',
                'callback'            => [$auth, 'acceptInvite'],
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

            // ── Players ───────────────────────────────────────────────────────
            register_rest_route('scs/v1', '/players', [
                [
                    'methods'             => 'GET',
                    'callback'            => [$players, 'index'],
                    'permission_callback' => '__return_true',
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
        });
    }
}
