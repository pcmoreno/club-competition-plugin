<?php

declare(strict_types=1);

namespace SCS\Services;

class EmailNotificationService
{
    public function sendInvite(string $email, string $token): bool
    {
        $url = $this->frontendUrl('/accept-invite?token=' . urlencode($token));

        $subject = 'Welcome to Schaakclub Santpoort — set your password';
        $body    = "You've been invited to join Schaakclub Santpoort's competition portal.\n\n"
            . "Set your password to activate your account:\n{$url}\n\n"
            . "This link expires in 7 days.";

        return wp_mail($email, $subject, $body);
    }

    public function sendPasswordReset(string $email, string $token): bool
    {
        $url = $this->frontendUrl('/reset-password?token=' . urlencode($token));

        $subject = 'Reset your Schaakclub Santpoort password';
        $body    = "A password reset was requested for your account.\n\n"
            . "Reset your password here:\n{$url}\n\n"
            . "This link expires in 1 hour. If you didn't request this, ignore this email.";

        return wp_mail($email, $subject, $body);
    }

    private function frontendUrl(string $path): string
    {
        return rtrim((string)home_url(), '/') . $path;
    }
}
