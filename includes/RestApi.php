<?php

declare(strict_types=1);

namespace SCS\includes;

use Symfony\Component\DependencyInjection\ContainerBuilder;

class RestApi {
    public static function register( ContainerBuilder $container ) {
        // Register REST API routes at /wp-json/scs/v1/
        add_action( 'rest_api_init', function() use ( $container ) {
            // Routes will be registered here
        });
    }
}
