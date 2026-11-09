<?php
/**
 * Bootstrap: wires hooks, cron, admin, REST (OAuth callback) and WP-CLI.
 */

namespace BastionBackup;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public const CRON_SCHEDULED = 'bastion_backup_scheduled';
	public const CRON_TICK      = 'bastion_backup_tick';

	public static function cap(): string {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	public static function boot(): void {
		add_action( self::CRON_SCHEDULED, array( Jobs::class, 'startScheduled' ) );
		add_action( self::CRON_TICK, array( Jobs::class, 'cronTick' ) );
		add_action( 'template_redirect', array( self::class, 'maintenance' ), 0 );
		add_action( 'rest_api_init', array( OAuth::class, 'registerRoutes' ) );
		add_action( 'update_option_bastion_backup_settings', array( self::class, 'reschedule' ), 10, 0 );

		if ( is_admin() ) {
			Admin::init();
			Ajax::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'bastion', Cli::class );
		}
	}

	public static function activate(): void {
		Storage::ensure();
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
		self::reschedule();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_SCHEDULED );
		wp_clear_scheduled_hook( self::CRON_TICK );
		delete_option( 'bastion_backup_maintenance' );
	}

	public static function reschedule(): void {
		wp_clear_scheduled_hook( self::CRON_SCHEDULED );
		$sched = Settings::get()['schedule'];
		if ( 'none' !== $sched && in_array( $sched, array_keys( wp_get_schedules() ), true ) ) {
			wp_schedule_event( time() + 300, $sched, self::CRON_SCHEDULED );
		}
	}

	/** Visitor-facing maintenance page while a restore rewrites files/tables. */
	public static function maintenance(): void {
		$until = (int) get_option( 'bastion_backup_maintenance', 0 );
		if ( $until > time() && ! current_user_can( self::cap() ) ) {
			status_header( 503 );
			header( 'Retry-After: 300' );
			nocache_headers();
			wp_die(
				esc_html__( 'This site is being restored from a backup. Please check back in a few minutes.', 'bastion-backup' ),
				esc_html__( 'Maintenance', 'bastion-backup' ),
				array( 'response' => 503 )
			);
		}
	}
}
