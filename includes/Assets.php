<?php

declare(strict_types=1);

namespace SCS\includes;

class Assets
{
    /**
     * Enqueues the viewer bundle and injects the bootstrap payload the React
     * app reads as `window.scsBootstrap` (see js/app/bootstrap.js).
     *
     * This payload is identical for every visitor, and must stay that way.
     * wp_localize_script writes it into the page HTML, and a full-page cache
     * (SG Dynamic Cache, any CDN) stores that HTML keyed by URL and replays it
     * to whoever asks next. Such caches skip logged-in WordPress users by
     * spotting the wordpress_logged_in_* cookie — which our members never get,
     * since they carry scs_token, which the cache reads as anonymous. So a
     * signed-in render would be served to the public, email address and all.
     *
     * The session therefore travels by cookie, which is per-browser and can't
     * be copied between visitors by a cache: the httpOnly scs_token authorizes,
     * and the readable scs_ui hint tells the frontend who it is at first paint
     * (see AuthController::setSessionCookies).
     *
     * The CSRF token is not injected either — issuing one mutates the scs_csrf
     * cookie, which must not happen on a GET; the app fetches it lazily via
     * GET /auth/csrf-token.
     *
     * Nor is a wp_rest nonce. No scs/v1 route verifies one, and core's
     * rest_cookie_check_errors() rejects a nonce that is present but stale with
     * a 403 before any permission_callback runs — so a cached page older than
     * the nonce tick would break every API call.
     */
    public static function enqueue_frontend(): void
    {
        $asset_file = dirname(__FILE__) . '/../build/viewer.asset.php';

        if (! file_exists($asset_file)) {
            return;
        }

        $asset = require $asset_file;

        wp_enqueue_script(
            'scs-viewer',
            plugins_url('build/viewer.js', dirname(__FILE__)),
            $asset['dependencies'],
            $asset['version'],
            true
        );

        wp_enqueue_style(
            'scs-viewer',
            plugins_url('build/viewer.css', dirname(__FILE__)),
            [],
            $asset['version']
        );

        wp_localize_script('scs-viewer', 'scsBootstrap', [
            'apiRoot'  => esc_url_raw(rest_url('scs/v1/')),
            'buildUrl' => plugins_url('build/', dirname(__FILE__)),
            // Safe to inject despite the caching note above: the same for every
            // visitor, which is the whole test this payload has to pass.
            'version'  => SCS_VERSION,
        ]);
    }
}
