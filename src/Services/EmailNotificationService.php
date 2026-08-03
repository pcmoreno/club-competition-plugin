<?php

declare(strict_types=1);

namespace SCS\Services;

class EmailNotificationService
{
    // Mirrors RoundAbsenceService::MODE_REQUEST — kept as a local constant so
    // the mailer doesn't depend on the service that calls it.
    private const ABSENCE_MODE_REQUEST = 'request';

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

    /**
     * Tell the admins a member can't play a round. Two shapes, because the two
     * modes need different things from the reader: a `self` notice is already
     * recorded and is FYI, while a `request` arrives for a member who is already
     * on a board and asks them to act — so it carries the board to re-pair.
     *
     * The reason is passed through, never stored (see RoundAbsenceService).
     *
     * @param list<string> $recipients active admins
     * @param string $action 'declared' or 'withdrawn'
     */
    public function sendAbsenceNotice(
        array $recipients,
        string $playerName,
        string $seasonName,
        int $roundNumber,
        ?string $roundDate,
        string $mode,
        string $action,
        ?string $reason,
        ?string $pairing,
    ): bool {
        if ($recipients === []) {
            return false;
        }

        $round     = sprintf('round %d%s', $roundNumber, $roundDate !== null ? ' (' . $roundDate . ')' : '');
        $reasonLine = $this->reasonLine($reason);

        if ($action === 'withdrawn') {
            $subject = sprintf('%s can play %s after all', $playerName, $round);
            $body    = "{$playerName} has withdrawn their absence for {$round} of {$seasonName}.\n\n"
                . "They are back in for this round; the absence has been removed.";

            return wp_mail($recipients, $subject, $body);
        }

        if ($mode === self::ABSENCE_MODE_REQUEST) {
            $subject = sprintf('Action needed: %s can\'t play %s', $playerName, $round);
            $body    = "{$playerName} can't play {$round} of {$seasonName}.\n\n"
                . "They are already paired, so NOTHING has been changed — "
                . "please mark the absence and re-pair.\n\n"
                . ($pairing !== null ? "They are currently on {$pairing}.\n\n" : "Their board could not be resolved.\n\n")
                . $reasonLine;

            return wp_mail($recipients, $subject, $body);
        }

        $subject = sprintf('%s can\'t play %s', $playerName, $round);
        $body    = "{$playerName} has said they can't play {$round} of {$seasonName}.\n\n"
            . "They are not paired in this round, so they have been marked absent (personal, no scored bye).\n\n"
            . $reasonLine;

        return wp_mail($recipients, $subject, $body);
    }

    /**
     * The member's own words, flattened to one line and quoted. Interior
     * newlines would otherwise let a member lay out extra paragraphs that read
     * as system-generated in a mail the admin trusts.
     */
    private function reasonLine(?string $reason): string
    {
        $reason = $reason === null ? '' : trim((string)preg_replace('/\s+/u', ' ', $reason));

        return $reason === '' ? 'No reason given.' : "Reason given: \"{$reason}\"\n";
    }

    /**
     * Build a link into the SPA. The app is a hash-routed single page mounted
     * on whichever page holds the [clubcompetitie] shortcode, so links must be
     * "{app page}#{route}" — a bare path would 404 or land on the default view.
     */
    private function frontendUrl(string $path): string
    {
        return $this->appBaseUrl() . '#' . $path;
    }

    /**
     * The URL of the page hosting the app, in order of preference:
     *   1. SCS_APP_URL constant — explicit config in wp-config.php (recommended;
     *      same pattern as SCS_JWT_SECRET). Set it to the shortcode page.
     *   2. scs_app_url option — recorded by the shortcode on render, a zero-config
     *      fallback so invites still work if the constant was never set.
     *   3. home_url — last resort; likely the wrong page, so configure SCS_APP_URL.
     */
    private function appBaseUrl(): string
    {
        if (defined('SCS_APP_URL') && is_string(SCS_APP_URL) && SCS_APP_URL !== '') {
            return SCS_APP_URL;
        }

        $captured = get_option('scs_app_url');
        if (is_string($captured) && $captured !== '') {
            return $captured;
        }

        return rtrim((string)home_url(), '/') . '/';
    }
}
