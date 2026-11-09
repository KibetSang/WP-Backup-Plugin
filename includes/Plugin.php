<?php
/**
 * Wires the plugin into WordPress: cron (schedule and job ticks), the maintenance page, the OAuth callback, the admin
 * screens, Site Health and WP-CLI.
 *
 * @package BastionBackup
 */

namespace BastionBackup;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public const CRON_SCHEDULED = 'bastion_backup_scheduled';
	public const CRON_TICK      = 'bastion_backup_tick';
	public const CRON_SWEEP     = 'bastion_backup_sweep';

	public static function cap(): string {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	public static function boot(): void {
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) );
		add_action( self::CRON_SCHEDULED, array( Jobs::class, 'startScheduled' ) );
		add_action( self::CRON_TICK, array( Jobs::class, 'cronTick' ), 10, 2 );
		add_action( self::CRON_SWEEP, array( Uploads::class, 'sweep' ) );
		add_action( 'template_redirect', array( self::class, 'maintenance' ), 0 );
		add_action( 'rest_api_init', array( OAuth::class, 'registerRoutes' ) );
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'reschedule' ), 10, 0 );
		add_filter( 'site_status_tests', array( SiteHealth::class, 'tests' ) );

		if ( is_admin() ) {
			Admin::init();
			Ajax::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'bastion', Cli::class );
		}
	}

	/** @param array<string,array{interval:int,display:string}> $s */
	public static function schedules( array $s ): array {
		if ( ! isset( $s['weekly'] ) ) {
			$s['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'bastion-backup' ),
			);
		}
		return $s;
	}

	public static function activate(): void {
		Storage::ensure();
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
		self::reschedule();
		if ( ! wp_next_scheduled( self::CRON_SWEEP ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_SWEEP );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_SCHEDULED );
		wp_clear_scheduled_hook( self::CRON_TICK );
		wp_clear_scheduled_hook( self::CRON_SWEEP );
		delete_option( 'bastion_backup_maintenance' );
	}

	/** The scheduled backup, at the chosen time of day in the site's time zone. */
	public static function reschedule(): void {
		wp_clear_scheduled_hook( self::CRON_SCHEDULED );
		$s = Settings::get();
		if ( 'none' === $s['schedule'] ) {
			return;
		}
		$first = time() + 300;
		if ( in_array( $s['schedule'], array( 'daily', 'weekly' ), true ) ) {
			[ $h, $m ] = array_map( 'intval', explode( ':', (string) $s['schedule_time'] ) );
			$tz        = wp_timezone();
			$at        = ( new \DateTimeImmutable( 'now', $tz ) )->setTime( $h, $m );
			if ( $at->getTimestamp() <= time() ) {
				$at = $at->modify( '+1 day' );
			}
			$first = $at->getTimestamp();
		}
		wp_schedule_event( $first, (string) $s['schedule'], self::CRON_SCHEDULED );
	}

	/**
	 * Visitors see "being restored" (503 with Retry-After) while a restore rewrites the site. Driven by an option, not
	 * .maintenance, because .maintenance would also block admin-ajax, which drives the restore.
	 */
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
