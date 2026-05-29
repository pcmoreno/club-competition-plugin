<?php

declare(strict_types=1);

namespace SCS\includes;

class Assets {
    public static function boot() {
        // Enqueue frontend scripts and styles
    }

    public static function enqueue_frontend() {
        $asset_file = dirname( __FILE__ ) . '/../build/viewer.asset.php';

        if ( ! file_exists( $asset_file ) ) {
            return;
        }

        $asset = require $asset_file;

        wp_enqueue_script(
            'scs-viewer',
            plugins_url( 'build/viewer.js', dirname( __FILE__ ) ),
            $asset['dependencies'],
            $asset['version'],
            true
        );

        wp_enqueue_style(
            'scs-viewer',
            plugins_url( 'build/viewer.css', dirname( __FILE__ ) ),
            [],
            $asset['version']
        );
    }
}
