<?php

declare(strict_types=1);

namespace SCS;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class Container {
    private static ?ContainerBuilder $instance = null;

    public static function boot(): ContainerBuilder {
        if (self::$instance === null) {
            self::$instance = self::build();

            $container = self::$instance;
            includes\RestApi::register($container);
            add_action('wp_enqueue_scripts', [includes\Assets::class, 'enqueue_frontend']);
            add_shortcode('clubcompetitie', [includes\Shortcode::class, 'render']);

            if ( defined( 'WP_CLI' ) && WP_CLI ) {
                \WP_CLI::add_command( 'scs migrate', new Command\MigrateCommand() );
            }
        }

        return self::$instance;
    }

    private static function build(): ContainerBuilder {
        $container = new ContainerBuilder();

        $container->register('db_connection', Connection::class)
            ->setFactory([self::class, 'createDbConnection']);

        $container->compile();

        return $container;
    }

    public static function createDbConnection(): Connection {
        return DriverManager::getConnection([
            'driver'   => 'pdo_mysql',
            'host'     => DB_HOST,
            'dbname'   => DB_NAME,
            'user'     => DB_USER,
            'password' => DB_PASSWORD,
            'charset'  => 'utf8mb4',
        ]);
    }
}
