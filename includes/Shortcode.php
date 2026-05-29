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
        // Render the [clubcompetitie] shortcode
        return '<div id="scs-app"></div>';
    }
}
