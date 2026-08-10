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
            includes\RestApi::register($container);
            add_action('wp_enqueue_scripts', [includes\Assets::class, 'enqueue_frontend']);
            add_shortcode('clubcompetitie', [includes\Shortcode::class, 'render']);

            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::add_command('scs migrate', new Command\MigrateCommand());
                \WP_CLI::add_command('scs create-admin', $container->get('create_admin_command'));
                \WP_CLI::add_command('scs fetch-knsb-ratings', $container->get('fetch_knsb_ratings_command'));
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

        $container->register('standings_snapshot_repository', Repository\StandingsSnapshotRepository::class)
            ->addArgument(new Reference('db_connection'));

        $container->register('member_repository', Repository\MemberRepository::class)
            ->addArgument(new Reference('db_connection'))
            ->setPublic(true);

        $container->register('admin_repository', Repository\AdminRepository::class)
            ->addArgument(new Reference('db_connection'))
            ->setPublic(true);

        $container->register('season_contact_repository', Repository\SeasonContactRepository::class)
            ->addArgument(new Reference('db_connection'));

        // ── Services ──────────────────────────────────────────────────────────
        $container->register('jwt_service', Services\JwtService::class)
            ->setPublic(true);

        $container->register('email_notification_service', Services\EmailNotificationService::class);

        $container->register('rate_limiter_service', Services\RateLimiterService::class);

        $container->register('auth_context_service', Services\AuthContextService::class)
            ->setPublic(true)
            ->addArgument(new Reference('jwt_service'))
            ->addArgument(new Reference('member_repository'))
            ->addArgument(new Reference('admin_repository'));

        $container->register('auth_service', Services\AuthService::class)
            ->addArgument(new Reference('member_repository'))
            ->addArgument(new Reference('admin_repository'))
            ->addArgument(new Reference('jwt_service'))
            ->addArgument(new Reference('email_notification_service'))
            ->addArgument(new Reference('rate_limiter_service'));

        $container->register('serializer_service', Services\SerializerService::class);

        $container->register('settings_validator', Services\SettingsValidator::class)
            ->addArgument(new Reference('settings_resolver'));

        $container->register('player_display_service', Services\PlayerDisplayService::class)
            ->addArgument(new Reference('season_player_repository'))
            ->addArgument(new Reference('player_repository'));

        $container->register('player_tournament_service', Services\PlayerTournamentService::class)
            ->addArgument(new Reference('season_repository'))
            ->addArgument(new Reference('season_player_repository'))
            ->addArgument(new Reference('round_repository'))
            ->addArgument(new Reference('game_repository'))
            ->addArgument(new Reference('attendance_repository'))
            ->addArgument(new Reference('standings_snapshot_repository'))
            ->addArgument(new Reference('player_display_service'));

        $container->register('transaction_manager', Services\TransactionManager::class)
            ->addArgument(new Reference('db_connection'));

        $container->register('player_merge_service', Services\PlayerMergeService::class)
            ->addArgument(new Reference('transaction_manager'))
            ->addArgument(new Reference('player_repository'))
            ->addArgument(new Reference('season_repository'))
            ->addArgument(new Reference('season_player_repository'))
            ->addArgument(new Reference('member_repository'));

        $container->register('player_home_service', Services\PlayerHomeService::class)
            ->addArgument(new Reference('season_repository'))
            ->addArgument(new Reference('season_player_repository'))
            ->addArgument(new Reference('round_repository'))
            ->addArgument(new Reference('game_repository'))
            ->addArgument(new Reference('attendance_repository'))
            ->addArgument(new Reference('standings_snapshot_repository'))
            ->addArgument(new Reference('player_display_service'));

        $container->register('season_contact_service', Services\SeasonContactService::class)
            ->addArgument(new Reference('season_contact_repository'))
            ->addArgument(new Reference('admin_repository'));

        $container->register('round_absence_service', Services\RoundAbsenceService::class)
            ->addArgument(new Reference('season_repository'))
            ->addArgument(new Reference('season_player_repository'))
            ->addArgument(new Reference('round_repository'))
            ->addArgument(new Reference('game_repository'))
            ->addArgument(new Reference('attendance_repository'))
            ->addArgument(new Reference('player_repository'))
            ->addArgument(new Reference('season_contact_service'))
            ->addArgument(new Reference('player_display_service'))
            ->addArgument(new Reference('email_notification_service'))
            ->addArgument(new Reference('rate_limiter_service'));

        $container->register('knsb_rating_list_fetcher', Services\KnsbRatingListFetcher::class);

        // Under uploads/, not the plugin dir: the list is personal data of ~20k
        // non-users, and a plugin subdirectory is web-reachable (and wiped by a
        // reinstall). The store hardens the directory and migrates any file left
        // at the old location.
        // The uploads path is resolved inside the store on first use, not here:
        // this container is rebuilt every request, and the store is touched only
        // during a KNSB sync.
        $container->register('knsb_rating_store', Services\KnsbRatingStore::class)
            ->addArgument(SCS_PLUGIN_PATH . 'resources/KnsbRatings');

        $container->register('knsb_name_normalizer', Services\KnsbNameNormalizer::class);

        $container->register('knsb_rating_sync_service', Services\KnsbRatingSyncService::class)
            ->addArgument(new Reference('player_repository'))
            ->addArgument(new Reference('knsb_rating_store'))
            ->addArgument(new Reference('knsb_name_normalizer'));

        $container->register('season_import_service', Services\SeasonImportService::class)
            ->setPublic(true)
            ->addArgument(new Reference('transaction_manager'))
            ->addArgument(new Reference('player_repository'))
            ->addArgument(new Reference('season_repository'))
            ->addArgument(new Reference('season_player_repository'))
            ->addArgument(new Reference('round_repository'))
            ->addArgument(new Reference('game_repository'))
            ->addArgument(new Reference('attendance_repository'))
            ->addArgument(new Reference('standings_snapshot_repository'))
            ->addArgument(SCS_PLUGIN_PATH . 'fixtures');

        $container->register('validator', ValidatorInterface::class)
            ->setFactory([self::class, 'createValidator']);

        $container->register('csrf_token_manager', CsrfTokenManager::class)
            ->setPublic(true)
            ->setFactory([self::class, 'createCsrfTokenManager']);

        // ── Engine (scoring) ──────────────────────────────────────────────────
        $container->register('points_calculator', Engine\Scoring\Metric\PointsCalculator::class);
        $container->register('wins_calculator', Engine\Scoring\Metric\WinsCalculator::class);
        $container->register('sonneborn_berger_calculator', Engine\Scoring\Metric\SonnebornBergerCalculator::class);
        $container->register('buchholz_calculator', Engine\Scoring\Metric\BuchholzCalculator::class);
        $container->register('performance_rating_calculator', Engine\Scoring\Metric\PerformanceRatingCalculator::class);

        $container->register('player_score_calculator', Engine\Scoring\PlayerScoreCalculator::class)
            ->addArgument([
                new Reference('points_calculator'),
                new Reference('wins_calculator'),
                new Reference('sonneborn_berger_calculator'),
                new Reference('buchholz_calculator'),
                new Reference('performance_rating_calculator'),
            ]);

        $container->register('standings_calculator', Engine\Scoring\StandingsCalculator::class);

        $container->register('scoring_strategy_resolver', Engine\ScoringStrategyResolver::class)
            ->addArgument(new Reference('player_score_calculator'))
            ->addArgument(new Reference('standings_calculator'));

        $container->register('pairing_engine_resolver', Engine\PairingEngineResolver::class)
            ->addArgument(new Reference('settings_resolver'));

        $container->register('settings_resolver', Engine\SettingsResolver::class);

        $container->register('round_service', Services\RoundService::class)
            ->addArgument(new Reference('scoring_strategy_resolver'))
            ->addArgument(new Reference('transaction_manager'))
            ->addArgument(new Reference('season_repository'))
            ->addArgument(new Reference('season_player_repository'))
            ->addArgument(new Reference('round_repository'))
            ->addArgument(new Reference('game_repository'))
            ->addArgument(new Reference('attendance_repository'))
            ->addArgument(new Reference('standings_snapshot_repository'))
            ->addArgument(new Reference('pairing_engine_resolver'))
            ->addArgument(new Reference('settings_resolver'));

        // ── Controllers (public — fetched by RestApi) ─────────────────────────
        $container->register('auth_controller', Controller\AuthController::class)
            ->setPublic(true)
            ->addArgument(new Reference('validator'))
            ->addArgument(new Reference('auth_service'))
            ->addArgument(new Reference('csrf_token_manager'))
            ->addArgument(new Reference('auth_context_service'))
            ->addArgument(new Reference('member_repository'))
            ->addArgument(new Reference('admin_repository'))
            ->addArgument(new Reference('player_repository'))
            ->addArgument(new Reference('serializer_service'));

        $container->register('me_controller', Controller\MeController::class)
            ->setPublic(true)
            ->addArgument(new Reference('validator'))
            ->addArgument(new Reference('auth_context_service'))
            ->addArgument(new Reference('player_repository'))
            ->addArgument(new Reference('player_home_service'))
            ->addArgument(new Reference('round_absence_service'))
            ->addArgument(new Reference('serializer_service'));

        $container->register('player_controller', Controller\PlayerController::class)
            ->setPublic(true)
            ->addArgument(new Reference('validator'))
            ->addArgument(new Reference('player_repository'))
            ->addArgument(new Reference('member_repository'))
            ->addArgument(new Reference('knsb_rating_sync_service'))
            ->addArgument(new Reference('auth_service'))
            ->addArgument(new Reference('serializer_service'))
            ->addArgument(new Reference('player_tournament_service'))
            ->addArgument(new Reference('player_merge_service'))
            ->addArgument(new Reference('transaction_manager'));

        $container->register('season_controller', Controller\SeasonController::class)
            ->setPublic(true)
            ->addArgument(new Reference('validator'))
            ->addArgument(new Reference('season_repository'))
            ->addArgument(new Reference('season_player_repository'))
            ->addArgument(new Reference('player_repository'))
            ->addArgument(new Reference('player_display_service'))
            ->addArgument(new Reference('standings_snapshot_repository'))
            ->addArgument(new Reference('round_repository'))
            ->addArgument(new Reference('player_tournament_service'))
            ->addArgument(new Reference('serializer_service'))
            ->addArgument(new Reference('settings_validator'))
            ->addArgument(new Reference('settings_resolver'))
            ->addArgument(new Reference('game_repository'))
            ->addArgument(new Reference('attendance_repository'))
            ->addArgument(new Reference('season_contact_repository'))
            ->addArgument(new Reference('season_contact_service'))
            ->addArgument(new Reference('admin_repository'))
            ->addArgument(new Reference('auth_context_service'))
            ->addArgument(new Reference('transaction_manager'));

        $container->register('admin_controller', Controller\AdminController::class)
            ->setPublic(true)
            ->addArgument(new Reference('validator'))
            ->addArgument(new Reference('admin_repository'))
            ->addArgument(new Reference('season_contact_repository'))
            ->addArgument(new Reference('auth_service'))
            ->addArgument(new Reference('auth_context_service'))
            ->addArgument(new Reference('serializer_service'))
            ->addArgument(new Reference('transaction_manager'));

        $container->register('round_controller', Controller\RoundController::class)
            ->setPublic(true)
            ->addArgument(new Reference('validator'))
            ->addArgument(new Reference('round_repository'))
            ->addArgument(new Reference('game_repository'))
            ->addArgument(new Reference('attendance_repository'))
            ->addArgument(new Reference('season_repository'))
            ->addArgument(new Reference('player_display_service'))
            ->addArgument(new Reference('serializer_service'))
            ->addArgument(new Reference('round_service'));

        $container->register('import_controller', Controller\ImportController::class)
            ->setPublic(true)
            ->addArgument(new Reference('validator'))
            ->addArgument(new Reference('season_import_service'));

        $container->register('knsb_controller', Controller\KnsbController::class)
            ->setPublic(true)
            ->addArgument(new Reference('validator'))
            ->addArgument(new Reference('knsb_rating_list_fetcher'))
            ->addArgument(new Reference('knsb_rating_store'));

        $container->register('create_admin_command', Command\CreateAdminCommand::class)
            ->setPublic(true)
            ->addArgument(new Reference('auth_service'));

        $container->register('fetch_knsb_ratings_command', Command\FetchKnsbRatingsCommand::class)
            ->setPublic(true)
            ->addArgument(new Reference('knsb_rating_list_fetcher'))
            ->addArgument(new Reference('knsb_rating_store'));

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
