<?php

declare(strict_types=1);

namespace SCS;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

class Container {
	private static $instance = null;

	/**
	 * Boot and return the DI container.
	 */
	public static function boot() {
		if ( self::$instance === null ) {
			self::$instance = self::build();
		}
		return self::$instance;
	}

	/**
	 * Build the container with all service definitions.
	 */
	private static function build() {
		$container = new ContainerBuilder();

		// =========== Database ===========
		$container->register( 'db_connection' )
			->setFactory( [ self::class, 'createDbConnection' ] );

		// =========== Validators ===========
		$container->register( 'validator' )
			->setFactory( [ Validation::class, 'createValidator' ] );

		// =========== Serializer ===========
		$container->register( 'serializer' )
			->setFactory( [ self::class, 'createSerializer' ] );

		// =========== Repositories ===========
		$container->register( 'player_repository', Repository\PlayerRepository::class )
			->addArgument( new Reference( 'db_connection' ) );

		$container->register( 'season_repository', Repository\SeasonRepository::class )
			->addArgument( new Reference( 'db_connection' ) );

		$container->register( 'season_player_repository', Repository\SeasonPlayerRepository::class )
			->addArgument( new Reference( 'db_connection' ) );

		$container->register( 'round_repository', Repository\RoundRepository::class )
			->addArgument( new Reference( 'db_connection' ) );

		$container->register( 'game_repository', Repository\GameRepository::class )
			->addArgument( new Reference( 'db_connection' ) );

		$container->register( 'attendance_repository', Repository\AttendanceRepository::class )
			->addArgument( new Reference( 'db_connection' ) );

		$container->register( 'ranking_repository', Repository\RankingRepository::class )
			->addArgument( new Reference( 'db_connection' ) );

		$container->register( 'member_repository', Repository\MemberRepository::class )
			->addArgument( new Reference( 'db_connection' ) );

		$container->register( 'admin_repository', Repository\AdminRepository::class )
			->addArgument( new Reference( 'db_connection' ) );

		// =========== Services ===========
		$container->register( 'auth_service', Security\Auth\AuthService::class )
			->addArgument( new Reference( 'member_repository' ) )
			->addArgument( new Reference( 'admin_repository' ) );

		$container->register( 'notification_service', Shared\Notification\WpMailNotificationService::class );

		$container->register( 'knsb_service', Services\KnsbSyncService::class )
			->addArgument( new Reference( 'player_repository' ) );

		// =========== Security / Auth ===========
		$container->register( 'jwt_secret' )
			->setFactory( [ self::class, 'getJwtSecret' ] );

		$container->register( 'member_provider', Security\Auth\MemberProvider::class )
			->addArgument( new Reference( 'member_repository' ) );

		$container->register( 'admin_provider', Security\Auth\AdminProvider::class )
			->addArgument( new Reference( 'admin_repository' ) );

		$container->register( 'jwt_authenticator', Security\Auth\JwtAuthenticator::class )
			->addArgument( new Reference( 'jwt_secret' ) )
			->addArgument( new Reference( 'member_provider' ) )
			->addArgument( new Reference( 'admin_provider' ) );

		// =========== Controllers ===========
		$container->register( 'player_controller', Controller\PlayerController::class )
			->addArgument( new Reference( 'player_repository' ) )
			->addArgument( new Reference( 'validator' ) )
			->addArgument( new Reference( 'serializer' ) );

		// =========== REST API & Frontend ===========
		$container->register( 'rest_api' )
			->setFactory( [ includes\RestApi::class, 'register' ] )
			->addArgument( $container );

		$container->register( 'assets' )
			->setFactory( [ includes\Assets::class, 'boot' ] );

		$container->register( 'shortcode' )
			->setFactory( [ includes\Shortcode::class, 'boot' ] );

		// =========== Installer / Activation ===========
		$container->register( 'installer' )
			->setFactory( [ includes\Database::class, 'boot' ] );

		// =========== Plugin Init ===========
		$container->register( 'plugin' )
			->setFactory( [ self::class, 'createPluginInitializer' ] )
			->addArgument( $container );

		// =========== WP-CLI Commands ===========
		$container->register( 'command_create_admin', Command\CreateAdminCommand::class )
			->addArgument( new Reference( 'admin_repository' ) );

		$container->register( 'command_import', Command\ImportCommand::class )
			->addArgument( new Reference( 'player_repository' ) )
			->addArgument( new Reference( 'season_repository' ) );

		$container->register( 'command_sync_knsb', Command\SyncKnsbCommand::class )
			->addArgument( new Reference( 'knsb_service' ) );

		$container->compile();
		return $container;
	}

	/**
	 * Create database connection via WordPress.
	 */
	public static function createDbConnection() {
		global $wpdb;
		return new \Doctrine\DBAL\Connection(
			[
				'pdo' => new \PDO(
					'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
					DB_USER,
					DB_PASSWORD
				),
			],
			new \Doctrine\DBAL\Driver\PDO\MySQL\Driver()
		);
	}

	/**
	 * Create Symfony serializer with JSON encoder and object normalizer.
	 */
	public static function createSerializer() {
		return new Serializer(
			[ new ObjectNormalizer() ],
			[ new JsonEncoder() ]
		);
	}

	/**
	 * Get JWT secret from WordPress option or environment.
	 */
	public static function getJwtSecret() {
		return get_option( 'scs_jwt_secret' ) ?: getenv( 'SCS_JWT_SECRET' );
	}

	/**
	 * Create the plugin initializer that handles hooks.
	 */
	public static function createPluginInitializer( ContainerBuilder $container ) {
		return new PluginInitializer( $container );
	}
}
