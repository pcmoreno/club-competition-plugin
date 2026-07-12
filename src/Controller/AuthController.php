<?php

declare(strict_types=1);

namespace SCS\Controller;

use SCS\Entity\Enum\Role;
use SCS\Exception\UnauthorizedException;
use SCS\Repository\AdminRepository;
use SCS\Repository\MemberRepository;
use SCS\Repository\PlayerRepository;
use SCS\Request\AcceptInviteRequest;
use SCS\Request\ChangePasswordRequest;
use SCS\Request\ForgotPasswordRequest;
use SCS\Request\LoginRequest;
use SCS\Request\ResetPasswordRequest;
use SCS\Security\RequestContext;
use SCS\Services\AuthContextService;
use SCS\Services\AuthService;
use SCS\Services\JwtService;
use SCS\Services\SerializerService;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AuthController extends RestController
{
    public const CSRF_TOKEN_ID = 'scs_admin_write';

    public function __construct(
        ValidatorInterface $validator,
        private readonly AuthService $authService,
        private readonly CsrfTokenManager $csrfTokenManager,
        private readonly AuthContextService $authContext,
        private readonly MemberRepository $memberRepository,
        private readonly AdminRepository $adminRepository,
        private readonly PlayerRepository $playerRepository,
        private readonly SerializerService $serializer,
    ) {
        parent::__construct($validator);
    }

    public function login(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $input = LoginRequest::fromRequest($request);
            $this->validate($input);

            $result    = $this->authService->login($input->email, $input->password, $this->clientIp());
            $csrfToken = $this->csrfTokenManager->refreshToken(self::CSRF_TOKEN_ID)->getValue();

            $this->setTokenCookie($result['token']);

            return $this->ok([
                'role'       => $result['role'],
                'player_id'  => $result['player_id'] ?? null,
                'email'      => $result['email'],
                'csrf_token' => $csrfToken,
            ]);
        });
    }

    public function logout(\WP_REST_Request $request): \WP_REST_Response
    {
        $this->clearTokenCookie();
        $this->csrfTokenManager->removeToken(self::CSRF_TOKEN_ID);

        return $this->noContent();
    }

    public function csrfToken(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () {
            $csrfToken = $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue();

            return $this->ok(['csrf_token' => $csrfToken]);
        });
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

    /**
     * Report whether an invite token is still usable, without consuming it, so
     * the accept-invite page can show a friendly landing on arrival.
     */
    public function inviteStatus(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $token = (string)$request->get_param('token');

            return $this->ok($this->authService->inviteTokenStatus($token));
        });
    }

    public function forgotPassword(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $input = ForgotPasswordRequest::fromRequest($request);
            $this->validate($input);

            $this->authService->initiatePasswordReset($input->email, $this->clientIp());

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

    /**
     * The signed-in user's own account data, for the Account page. Members get
     * their linked player record too; admins have none. Hand-built payloads,
     * so no secret-bearing column (password_hash, tokens) can leak.
     */
    public function me(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () {
            $claims = $this->requireClaims();

            if ($claims['role'] === Role::Admin->value) {
                $admin = $this->adminRepository->findById($claims['sub']);
                if ($admin === null) {
                    throw new UnauthorizedException('Account not found.');
                }

                return $this->ok([
                    'role'       => Role::Admin->value,
                    'name'       => $admin->name,
                    'email'      => $admin->email,
                    'status'     => $admin->status->value,
                    'created_at' => $admin->created_at->format('Y-m-d'),
                ]);
            }

            $member = $this->memberRepository->findById($claims['sub']);
            if ($member === null) {
                throw new UnauthorizedException('Account not found.');
            }
            $player = $this->playerRepository->findById($member->player_id);

            return $this->ok([
                'role'       => Role::Member->value,
                'email'      => $member->email,
                'status'     => $member->status->value,
                'created_at' => $member->created_at->format('Y-m-d'),
                'player'     => $player !== null
                    ? $this->serializer->serialize($player, SerializerService::GROUP_ADMIN)
                    : null,
            ]);
        });
    }

    /**
     * Change the signed-in user's password (current + new). On success the
     * service invalidates other sessions and returns a fresh token, which we
     * set as the new cookie so this session stays signed in.
     */
    public function changePassword(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(function () use ($request) {
            $claims = $this->requireClaims();

            $input = ChangePasswordRequest::fromRequest($request);
            $this->validate($input);

            $token = $this->authService->changePassword(
                $claims,
                $input->currentPassword,
                $input->newPassword,
            );
            $this->setTokenCookie($token);

            return $this->ok(['message' => 'Password updated.']);
        });
    }

    /**
     * Re-resolve the signed-in account from the cookie. The permission callback
     * already gated the route, but the controller needs the claims themselves;
     * treat an unexpected miss as unauthorized rather than 500.
     *
     * @return array{sub: int, role: string, pid: int|null, iat: int}
     */
    private function requireClaims(): array
    {
        $claims = $this->authContext->currentClaims();
        if ($claims === null) {
            throw new UnauthorizedException('Not authenticated.');
        }

        return $claims;
    }

    /**
     * REMOTE_ADDR only — X-Forwarded-For is attacker-controllable unless the
     * immediate hop is a known, trusted proxy, which isn't configured here.
     * Used solely as a rate-limit key, not for authorization decisions.
     *
     * TODO(proxy): behind SiteGround's TLS-terminating proxy REMOTE_ADDR may be
     * the shared proxy address rather than the real client, which would collapse
     * the per-IP counter into a single global one. Pending a prod check; if so,
     * resolve the client from a trusted-proxy X-Forwarded-For hop here. The
     * per-account counters are a partial backstop meanwhile.
     */
    private function clientIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    private function setTokenCookie(string $token): void
    {
        setcookie('scs_token', $token, [
            'expires'  => time() + JwtService::TOKEN_TTL_SECONDS,
            'path'     => '/',
            'secure'   => RequestContext::isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearTokenCookie(): void
    {
        setcookie('scs_token', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => RequestContext::isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
