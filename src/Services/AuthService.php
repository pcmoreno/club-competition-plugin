<?php

declare(strict_types=1);

namespace SCS\Services;

use SCS\Entity\Enum\AdminStatus;
use SCS\Entity\Enum\MemberStatus;
use SCS\Exception\NotFoundException;
use SCS\Exception\UnauthorizedException;
use SCS\Repository\AdminRepository;
use SCS\Repository\MemberRepository;

class AuthService
{
    public function __construct(
        private readonly MemberRepository $memberRepository,
        private readonly AdminRepository $adminRepository,
        private readonly JwtService $jwtService,
        private readonly EmailNotificationService $emailNotificationService,
    ) {
    }

    /** @return array{token: string, role: string} */
    public function login(string $email, string $password): array
    {
        $member = $this->memberRepository->findByEmail($email);
        if ($member !== null) {
            if (!password_verify($password, (string)$member->password_hash)) {
                throw new UnauthorizedException('Invalid credentials.');
            }
            if ($member->status !== MemberStatus::Active) {
                throw new UnauthorizedException('Account is not active.');
            }

            return [
                'token' => $this->jwtService->issue($member->id, 'ROLE_MEMBER'),
                'role'  => 'member',
            ];
        }

        $admin = $this->adminRepository->findByEmail($email);
        if ($admin !== null) {
            if (!password_verify($password, $admin->password_hash)) {
                throw new UnauthorizedException('Invalid credentials.');
            }
            if ($admin->status !== AdminStatus::Active) {
                throw new UnauthorizedException('Account is not active.');
            }

            return [
                'token' => $this->jwtService->issue($admin->id, 'ROLE_ADMIN'),
                'role'  => 'admin',
            ];
        }

        throw new UnauthorizedException('Invalid credentials.');
    }

    public function acceptInvite(string $token, string $password): void
    {
        $member = $this->memberRepository->findByInviteToken($token);
        if ($member === null) {
            throw new NotFoundException('Invalid or expired invite link.');
        }
        if ($member->invite_expires_at < new \DateTimeImmutable()) {
            throw new UnauthorizedException('Invite link has expired.');
        }

        $this->memberRepository->update($member->id, [
            'password_hash'     => password_hash($password, PASSWORD_BCRYPT),
            'invite_token'      => null,
            'invite_expires_at' => null,
            'status'            => MemberStatus::Active->value,
        ]);
    }

    public function initiatePasswordReset(string $email): void
    {
        $member = $this->memberRepository->findByEmail($email);
        if ($member === null || $member->status !== MemberStatus::Active) {
            // Silently return — don't reveal whether email exists
            return;
        }

        $resetToken = bin2hex(random_bytes(32));
        $expiresAt  = new \DateTimeImmutable('+1 hour');

        $this->memberRepository->update($member->id, [
            'reset_token'      => $resetToken,
            'reset_expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);

        $this->emailNotificationService->sendPasswordReset($member->email, $resetToken);
    }

    public function resetPassword(string $token, string $password): void
    {
        $member = $this->memberRepository->findByResetToken($token);
        if ($member === null) {
            throw new NotFoundException('Invalid or expired reset link.');
        }
        if ($member->reset_expires_at < new \DateTimeImmutable()) {
            throw new UnauthorizedException('Reset link has expired.');
        }

        $this->memberRepository->update($member->id, [
            'password_hash'    => password_hash($password, PASSWORD_BCRYPT),
            'reset_token'      => null,
            'reset_expires_at' => null,
        ]);
    }
}
