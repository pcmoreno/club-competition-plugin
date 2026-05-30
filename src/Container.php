<?php

declare(strict_types=1);

namespace SCS;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class Container
{
    private static ?ContainerBuilder $instance = null;

    public static function boot(): ContainerBuilder
    {
        if (self::$instance === null) {
            self::$instance = self::build();

            $container = self::$instance;
            Controller\RestController::setValidator(self::createValidator());
            includes\RestApi::register($container);
            add_action('wp_enqueue_scripts', [includes\Assets::class, 'enqueue_frontend']);
            add_shortcode('clubcompetitie', [includes\Shortcode::class, 'render']);

            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::add_command('scs migrate', new Command\MigrateCommand());
            }
        }

        return self::$instance;
    }

    private static function build(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        // ── Database ──────────────────────────────────────────────────────────
        $container->register('db_connection', Connection::class)
            ->setFactory([self::class, 'createDbConnection']);

        // ── Repositories ──────────────────────────────────────────────────────
        $container->register('player_repository', Repository\PlayerRepository::class)
            ->addArgument(new Reference('db_connection'));

        $container->register('season_repository', Repository\SeasonRepository::class)
            ->addArgument(new Reference('db_connection'));

        $container->register('season_player_repository', Repository\SeasonPlayerRepository::class)
            ->addArgument(new Reference('db_connection'));

        $container->register('round_repository', Repository\RoundRepository::class)
            ->addArgument(new Reference('db_connection'));

        $container->register('attendance_repository', Repository\AttendanceRepository::class)
            ->addArgument(new Reference('db_connection'));

        $container->register('game_repository', Repository\GameRepository::class)
            ->addArgument(new Reference('db_connection'));

        $container->register('member_repository', Repository\MemberRepository::class)
            ->addArgument(new Reference('db_connection'));

        $container->register('admin_repository', Repository\AdminRepository::class)
            ->addArgument(new Reference('db_connection'));

        // ── Services ──────────────────────────────────────────────────────────
        $container->register('jwt_service', Services\JwtService::class)
            ->setPublic(true);

        $container->register('email_notification_service', Services\EmailNotificationService::class);

        $container->register('auth_service', Services\AuthService::class)
            ->addArgument(new Reference('member_repository'))
            ->addArgument(new Reference('admin_repository'))
            ->addArgument(new Reference('jwt_service'))
            ->addArgument(new Reference('email_notification_service'));

        $container->register('serializer_service', Services\SerializerService::class);

        $container->register('csrf_token_manager', CsrfTokenManager::class)
            ->setPublic(true)
            ->setFactory([self::class, 'createCsrfTokenManager']);

        // ── Controllers (public — fetched by RestApi) ─────────────────────────
        $container->register('auth_controller', Controller\AuthController::class)
            ->setPublic(true)
            ->addArgument(new Reference('auth_service'))
            ->addArgument(new Reference('csrf_token_manager'));

        $container->register('player_controller', Controller\PlayerController::class)
            ->setPublic(true)
            ->addArgument(new Reference('player_repository'))
            ->addArgument(new Reference('serializer_service'));

        $container->register('season_controller', Controller\SeasonController::class)
            ->setPublic(true)
            ->addArgument(new Reference('season_repository'))
            ->addArgument(new Reference('season_player_repository'))
            ->addArgument(new Reference('player_repository'))
            ->addArgument(new Reference('serializer_service'));

        $container->register('round_controller', Controller\RoundController::class)
            ->setPublic(true)
            ->addArgument(new Reference('round_repository'))
            ->addArgument(new Reference('game_repository'))
            ->addArgument(new Reference('attendance_repository'))
            ->addArgument(new Reference('season_repository'))
            ->addArgument(new Reference('serializer_service'));

        $container->compile();

        return $container;
    }

    public static function createDbConnection(): Connection
    {
        return DriverManager::getConnection([
            'driver'   => 'pdo_mysql',
            'host'     => DB_HOST,
            'dbname'   => DB_NAME,
            'user'     => DB_USER,
            'password' => DB_PASSWORD,
            'charset'  => 'utf8mb4',
        ]);
    }

    public static function createValidator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public static function createCsrfTokenManager(): CsrfTokenManager
    {
        return new CsrfTokenManager(
            new UriSafeTokenGenerator(),
            new Security\CookieCsrfTokenStorage(),
            '',
        );
    }
}
