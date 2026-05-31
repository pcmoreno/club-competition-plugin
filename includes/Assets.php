<?php

declare(strict_types=1);

namespace SCS\includes;

use SCS\Services\JwtService;

class Assets
{
    /**
     * Enqueues the viewer bundle and injects the bootstrap payload the React
     * app reads as `window.scsBootstrap` (see js/app/bootstrap.js).
     *
     * The session role is decoded server-side from the httpOnly scs_token JWT
     * so a logged-in visitor is recognised on first paint without a round-trip.
     * The CSRF token is intentionally NOT injected here — issuing one mutates
     * the scs_csrf cookie, which must not happen on a GET; the app fetches it
     * lazily via GET /auth/csrf-token once it knows it's authenticated.
     */
    public static function enqueue_frontend(JwtService $jwt): void
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

        $token  = $_COOKIE['scs_token'] ?? null;
        $claims = is_string($token) ? $jwt->parse($token) : null;

        wp_localize_script('scs-viewer', 'scsBootstrap', [
            'apiRoot'   => esc_url_raw(rest_url('scs/v1/')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'role'      => $claims['role'] ?? null,
            'buildUrl'  => plugins_url('build/', dirname(__FILE__)),
        ]);
    }
}
