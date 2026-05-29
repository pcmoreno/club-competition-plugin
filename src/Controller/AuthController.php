<?php

declare(strict_types=1);

namespace SCS\Controller;

use SCS\Request\AcceptInviteRequest;
use SCS\Request\ForgotPasswordRequest;
use SCS\Request\LoginRequest;
use SCS\Request\ResetPasswordRequest;
use SCS\Services\AuthService;
use SCS\Services\JwtService;

class AuthController extends RestController
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    public function login(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $input = LoginRequest::fromRequest($request);
            $this->validate($input);

            $result = $this->authService->login($input->email, $input->password);
            $this->setTokenCookie($result['token']);

            return $this->ok(['role' => $result['role']]);
        });
    }

    public function logout(\WP_REST_Request $request): \WP_REST_Response
    {
        $this->clearTokenCookie();

        return $this->noContent();
    }

    public function acceptInvite(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $input = AcceptInviteRequest::fromRequest($request);
            $this->validate($input);

            $this->authService->acceptInvite($input->token, $input->password);

            return $this->ok(['message' => 'Account activated. You can now log in.']);
        });
    }

    public function forgotPassword(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $input = ForgotPasswordRequest::fromRequest($request);
            $this->validate($input);

            $this->authService->initiatePasswordReset($input->email);

            // Always return success to avoid email enumeration
            return $this->ok(['message' => 'If that email is registered, a reset link has been sent.']);
        });
    }

    public function resetPassword(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $input = ResetPasswordRequest::fromRequest($request);
            $this->validate($input);

            $this->authService->resetPassword($input->token, $input->password);

            return $this->ok(['message' => 'Password updated. You can now log in.']);
        });
    }

    private function setTokenCookie(string $token): void
    {
        setcookie('scs_token', $token, [
            'expires'  => time() + JwtService::TOKEN_TTL_SECONDS,
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
