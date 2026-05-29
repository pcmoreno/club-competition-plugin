<?php

declare(strict_types=1);

namespace SCS\Controller;

use SCS\Exception\NotFoundException;
use SCS\Exception\UnauthorizedException;
use SCS\Http\StatusCode;
use SCS\Service\AuthService;

class AuthController
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    public function login(\WP_REST_Request $request): \WP_REST_Response
    {
        $email    = trim((string)$request->get_param('email'));
        $password = (string)$request->get_param('password');

        if ($email === '' || $password === '') {
            return new \WP_REST_Response(['error' => 'Email and password are required.'], StatusCode::BAD_REQUEST);
        }

        try {
            $result = $this->authService->login($email, $password);
            $this->setTokenCookie($result['token']);

            return new \WP_REST_Response(['role' => $result['role']], StatusCode::OK);
        } catch (UnauthorizedException $e) {
            return new \WP_REST_Response(['error' => $e->getMessage()], StatusCode::UNAUTHORIZED);
        }
    }

    public function logout(\WP_REST_Request $request): \WP_REST_Response
    {
        $this->clearTokenCookie();

        return new \WP_REST_Response(null, StatusCode::NO_CONTENT);
    }

    public function acceptInvite(\WP_REST_Request $request): \WP_REST_Response
    {
        $token    = trim((string)$request->get_param('token'));
        $password = (string)$request->get_param('password');

        if ($token === '' || $password === '') {
            return new \WP_REST_Response(['error' => 'Token and password are required.'], StatusCode::BAD_REQUEST);
        }
        if (strlen($password) < 8) {
            return new \WP_REST_Response(['error' => 'Password must be at least 8 characters.'], StatusCode::BAD_REQUEST);
        }

        try {
            $this->authService->acceptInvite($token, $password);

            return new \WP_REST_Response(['message' => 'Account activated. You can now log in.'], StatusCode::OK);
        } catch (NotFoundException $e) {
            return new \WP_REST_Response(['error' => $e->getMessage()], StatusCode::NOT_FOUND);
        } catch (UnauthorizedException $e) {
            return new \WP_REST_Response(['error' => $e->getMessage()], StatusCode::UNAUTHORIZED);
        }
    }

    public function forgotPassword(\WP_REST_Request $request): \WP_REST_Response
    {
        $email = trim((string)$request->get_param('email'));

        if ($email === '') {
            return new \WP_REST_Response(['error' => 'Email is required.'], StatusCode::BAD_REQUEST);
        }

        $this->authService->initiatePasswordReset($email);

        // Always return success to avoid email enumeration
        return new \WP_REST_Response(['message' => 'If that email is registered, a reset link has been sent.'], StatusCode::OK);
    }

    public function resetPassword(\WP_REST_Request $request): \WP_REST_Response
    {
        $token    = trim((string)$request->get_param('token'));
        $password = (string)$request->get_param('password');

        if ($token === '' || $password === '') {
            return new \WP_REST_Response(['error' => 'Token and password are required.'], StatusCode::BAD_REQUEST);
        }
        if (strlen($password) < 8) {
            return new \WP_REST_Response(['error' => 'Password must be at least 8 characters.'], StatusCode::BAD_REQUEST);
        }

        try {
            $this->authService->resetPassword($token, $password);

            return new \WP_REST_Response(['message' => 'Password updated. You can now log in.'], StatusCode::OK);
        } catch (NotFoundException $e) {
            return new \WP_REST_Response(['error' => $e->getMessage()], StatusCode::NOT_FOUND);
        } catch (UnauthorizedException $e) {
            return new \WP_REST_Response(['error' => $e->getMessage()], StatusCode::UNAUTHORIZED);
        }
    }

    private function setTokenCookie(string $token): void
    {
        setcookie('scs_token', $token, [
            'expires'  => time() + 86400,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearTokenCookie(): void
    {
        setcookie('scs_token', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
