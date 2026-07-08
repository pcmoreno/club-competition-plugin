<?php

declare(strict_types=1);

namespace SCS\includes;

class Shortcode
{
    public static function boot()
    {
        add_shortcode('clubcompetitie', [ self::class, 'render' ]);
    }

    public static function render($atts = [])
    {
        // Remember which page hosts the app so emailed links (invite / reset)
        // can point back to it with the hash route the SPA router expects. The
        // plugin has no other way to know the host page; capture it on render.
        // Cheap: only writes when the permalink changes.
        $permalink = get_permalink();
        if (is_string($permalink) && $permalink !== '' && get_option('scs_app_url') !== $permalink) {
            update_option('scs_app_url', $permalink);
        }

        return '<div id="scs-app"></div>';
    }
}
