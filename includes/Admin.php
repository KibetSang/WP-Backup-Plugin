<?php
/**
 * The admin screen: one menu, seven tabs. Laid out like the Biosafety system's Backups page (the backup key panel,
 * "make a backup now", the list of backups with "check with the key", and how to restore), built from WordPress's own
 * admin parts (tabs, form tables, widefat lists, notices, buttons) so it looks at home in the dashboard.
 *
 * @package BastionBackup
 */

namespace BastionBackup;

use BastionBackup\Destinations\FileServer;
use BastionBackup\Destinations\OAuthDestination;
use BastionBackup\Destinations\Registry;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const SLUG = 'bastion-backup';

	public static function init(): void {
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_post_bbk_download', array( Ajax::class, 'download' ) );
		add_action( 'admin_post_bbk_installer', array( Ajax::class, 'installerDownload' ) );
		add_action( 'admin_post_bbk_log', array( Ajax::class, 'logDownload' ) );
	}

	/** The destination's logo (Google Drive and OneDrive: their own; the file server: a WordPress icon). */
	public static function brand( string $id, int $size = 20 ): string {
		$file = array( 'google' => 'google-drive.png', 'onedrive' => 'onedrive.png' )[ $id ] ?? '';
		if ( '' === $file ) {
			return sprintf( '<span class="dashicons dashicons-networking bbk-brand" style="font-size:%1$dpx;width:%1$dpx;height:%1$dpx" aria-hidden="true"></span>', $size );
		}
		return sprintf( '<img class="bbk-brand" src="%s" width="%d" height="%d" alt="">', esc_url( plugins_url( 'assets/brands/' . $file, BASTION_BACKUP_FILE ) ), $size, $size );
	}

	/** @return array<string,string> */
	public static function brandUrls(): array {
		return array(
			'google'   => plugins_url( 'assets/brands/google-drive.png', BASTION_BACKUP_FILE ),
			'onedrive' => plugins_url( 'assets/brands/onedrive.png', BASTION_BACKUP_FILE ),
		);
	}

	public static function url( string $tab = 'backups' ): string {
		$base = is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
		return add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab ), $base );
	}

	public static function menu(): void {
		add_menu_page( __( 'Bastion Backup', 'bastion-backup' ), __( 'Bastion Backup', 'bastion-backup' ), Plugin::cap(), self::SLUG, array( self::class, 'render' ), 'dashicons-shield', 80 );
	}

	/** @return array<string,string> */
	private static function tabs(): array {
		return array(
			'backups'      => __( 'Backups', 'bastion-backup' ),
			'restore'      => __( 'Restore', 'bastion-backup' ),
			'destinations' => __( 'Destinations', 'bastion-backup' ),
			'schedule'     => __( 'Schedule & retention', 'bastion-backup' ),
			'settings'     => __( 'Settings', 'bastion-backup' ),
			'installer'    => __( 'Installer', 'bastion-backup' ),
			'logs'         => __( 'Logs', 'bastion-backup' ),
		);
	}

	private static function tab(): string {
		$t = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'backups'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		return isset( self::tabs()[ $t ] ) ? $t : 'backups';
	}

	public static function assets( string $hook ): void {
		if ( ! str_contains( $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'bastion-backup', plugins_url( 'assets/admin.css', BASTION_BACKUP_FILE ), array(), BASTION_BACKUP_VERSION );
		wp_enqueue_script( 'bastion-backup', plugins_url( 'assets/admin.js', BASTION_BACKUP_FILE ), array(), BASTION_BACKUP_VERSION, true );
		$dests = array();
		foreach ( Registry::all() as $id => $d ) {
			$dests[ $id ] = array(
				'label'      => $d->label(),
				'configured' => $d->isConfigured(),
				'icon'       => self::brandUrls()[ $id ] ?? '',
			);
		}
		wp_localize_script(
			'bastion-backup',
			'bbkData',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'bastion_backup' ),
				'download'  => wp_nonce_url( admin_url( 'admin-post.php?action=bbk_download' ), 'bbk_download' ),
				'status'    => Jobs::status(),
				'dests'     => $dests,
				'piece'     => Uploads::PIECE,
				'tab'       => self::tab(),
				'restoreUrl'=> self::url( 'restore' ),
				'site'      => Settings::siteSlug(),
				'siteUrl'   => untrailingslashit( site_url() ),
				'i18n'      => array(
					'confirmDelete' => __( 'Delete this backup? It cannot be brought back.', 'bastion-backup' ),
					'working'       => __( 'Working…', 'bastion-backup' ),
					'copied'        => __( 'Copied.', 'bastion-backup' ),
					'bgNote'        => __( 'You can close this page: the job carries on in the background and this page picks it up again when you come back.', 'bastion-backup' ),
				),
			)
		);
	}

	public static function render(): void {
		if ( ! current_user_can( Plugin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage backups.', 'bastion-backup' ), 403 );
		}
		$tab = self::tab();
		echo '<div class="wrap bbk">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Bastion Backup', 'bastion-backup' ) . '</h1>';
		echo '<p class="bbk-lead">' . esc_html__( 'Encrypted copies of the whole site: WordPress, themes, plugins, uploads and the whole database. Only the backup key opens them, and this site does not have it.', 'bastion-backup' ) . '</p>';
		self::notices();
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Bastion Backup sections', 'bastion-backup' ) . '">';
		foreach ( self::tabs() as $id => $label ) {
			printf( '<a href="%s" class="nav-tab%s"%s>%s</a>', esc_url( self::url( $id ) ), $id === $tab ? ' nav-tab-active' : '', $id === $tab ? ' aria-current="page"' : '', esc_html( $label ) );
		}
		echo '</nav>';
		echo '<div id="bbk-job" class="bbk-job" hidden aria-live="polite"></div>';
		echo '<div class="bbk-tab">';
		call_user_func( array( self::class, 'tab' . str_replace( ' ', '', ucwords( str_replace( '-', ' ', $tab ) ) ) ) );
		echo '</div></div>';
		echo '<dialog id="bbk-dialog" class="bbk-dialog" aria-labelledby="bbk-dialog-title"><form method="dialog" id="bbk-dialog-form"></form></dialog>';
	}

	private static function notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$n = isset( $_GET['bbk_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['bbk_notice'] ) ) : '';
		if ( str_starts_with( $n, 'connected-' ) ) {
			$d = Registry::classes()[ substr( $n, 10 ) ] ?? null;
			if ( $d ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( /* translators: %s: Google Drive or OneDrive */ __( '%s is connected. Press Test to check it, then choose it under Schedule & retention.', 'bastion-backup' ), ( new $d() )->label() ) ) . '</p></div>';
			}
		} elseif ( 'oauth-error' === $n ) {
			$e = (string) get_transient( 'bbk_oauth_error' );
			delete_transient( 'bbk_oauth_error' );
			echo '<div class="notice notice-error"><p>' . esc_html( $e ?: __( 'Connecting did not finish.', 'bastion-backup' ) ) . '</p></div>';
		}
		if ( ! Keys::current() && 'backups' !== self::tab() ) {
			echo '<div class="notice notice-warning"><p>' . wp_kses( sprintf( /* translators: %s: link */ __( 'No backups are made until there is a backup key. <a href="%s">Make the backup key</a>.', 'bastion-backup' ), esc_url( self::url() ) ), array( 'a' => array( 'href' => true ) ) ) . '</p></div>';
		}
		if ( ! Core\Crypto::native() ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Backups work, but slowly.', 'bastion-backup' ) . '</strong> ' . esc_html(
				sprintf(
					/* translators: %s: PHP version */
					__( 'The PHP that runs this site (%s) does not have the sodium extension loaded, so the encryption uses WordPress\'s built-in copy of it, about 1,000 times slower (roughly 25 MB a minute).', 'bastion-backup' ),
					PHP_VERSION
				)
			) . '</p><p>' . esc_html( ServerCheck::sodiumAdvice() ) . ' ' . wp_kses( sprintf( /* translators: %s: link */ __( 'See <a href="%s">Settings → Server check</a> for what this site\'s PHP has loaded.', 'bastion-backup' ), esc_url( self::url( 'settings' ) . '#bbk-server' ) ), array( 'a' => array( 'href' => true ) ) ) . '</p></div>';
		}
		if ( Storage::publicPath() ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Backups are stored inside the website\'s folder, protected by deny rules and an unguessable name. For the strongest setup, define BASTION_BACKUP_STORAGE in wp-config.php as a folder outside the website (see Settings).', 'bastion-backup' ) . '</p></div>';
		}
	}

	private static function panel( string $title, string $sub, callable $body, string $actions = '', string $id = '', string $icon = '' ): void {
		printf( '<section class="bbk-panel"%s><header><div><h2>%s</h2>%s</div>%s</header><div class="bbk-body">', $id ? ' id="' . esc_attr( $id ) . '"' : '', $icon . esc_html( $title ), '' !== $sub ? '<p>' . wp_kses_post( $sub ) . '</p>' : '', $actions ? '<div class="bbk-actions">' . $actions . '</div>' : '' );
		$body();
		echo '</div></section>';
	}

	private static function tabBackups(): void {
		$key = Keys::current();
		self::panel(
			__( 'Backup key', 'bastion-backup' ),
			esc_html__( 'Backups are encrypted to this key. Only its public half is on this site.', 'bastion-backup' ),
			static function () use ( $key ): void {
				echo '<div id="bbk-key-made" class="notice notice-success inline bbk-keymade" hidden role="status"></div>';
				if ( $key ) {
					$by = get_user_by( 'login', $key['made_by'] );
					echo '<p id="bbk-key-line">' . wp_kses(
						sprintf(
							/* translators: 1: key id, 2: date, 3: user */
							__( 'Key <b class="bbk-ref">%1$s</b>, made %2$s by %3$s.', 'bastion-backup' ),
							esc_html( $key['id'] ),
							esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $key['made_at'] ) ),
							esc_html( $by ? $by->display_name : $key['made_by'] )
						),
						array( 'b' => array( 'class' => true ) )
					) . '</p>';
					echo '<p><button type="button" class="button bbk-danger" data-bbk="key-replace">' . esc_html__( 'Replace the key…', 'bastion-backup' ) . '</button></p>';
				} else {
					echo '<p>' . esc_html__( 'No backup key yet, so no backups are made. Make the key now: it is shown once, for you to keep safe.', 'bastion-backup' ) . '</p>';
				}
				echo '<form id="bbk-key-form" class="bbk-inline-form"' . ( $key ? ' hidden' : '' ) . '>';
				if ( $key ) {
					echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( /* translators: %s: key id */ __( 'A new key is used for the backups made from now on. The backups already made still need the old key (%s), so keep it until they are deleted. Replace the key if the old one may have been seen by someone else.', 'bastion-backup' ), $key['id'] ) ) . '</p></div>';
				}
				echo '<label for="bbk-key-pw">' . esc_html__( 'Your WordPress password', 'bastion-backup' ) . '</label> ';
				echo '<input type="password" id="bbk-key-pw" class="regular-text" autocomplete="current-password" required> ';
				echo '<button class="button button-primary">' . esc_html( $key ? __( 'Make a new key', 'bastion-backup' ) : __( 'Make the backup key', 'bastion-backup' ) ) . '</button>';
				if ( $key ) {
					echo ' <button type="button" class="button" data-bbk="key-cancel">' . esc_html__( 'Cancel', 'bastion-backup' ) . '</button>';
				}
				echo '</form>';
			}
		);

		$s       = Settings::get();
		$actions = '<button type="button" class="button button-primary" data-bbk="backup"' . ( $key ? '' : ' disabled' ) . '>' . esc_html__( 'Back up now', 'bastion-backup' ) . '</button>';
		self::panel(
			__( 'Make a backup', 'bastion-backup' ),
			esc_html__( 'Large sites are backed up in short steps, so it works on any hosting. The copy to Google Drive, OneDrive or your file server follows straight after.', 'bastion-backup' ),
			static function () use ( $s ): void {
				echo '<fieldset class="bbk-choices"><legend class="screen-reader-text">' . esc_html__( 'What to back up', 'bastion-backup' ) . '</legend>';
				printf( '<label><input type="checkbox" id="bbk-c-db" %s> %s</label>', checked( $s['backup_db'], true, false ), esc_html__( 'Database', 'bastion-backup' ) );
				printf( '<label><input type="checkbox" id="bbk-c-files" %s> %s</label>', checked( $s['backup_files'], true, false ), esc_html__( 'Files (WordPress, themes, plugins, uploads)', 'bastion-backup' ) );
				echo '</fieldset><fieldset class="bbk-choices"><legend>' . esc_html__( 'Also copy it to', 'bastion-backup' ) . '</legend>';
				$any = false;
				foreach ( Registry::all() as $id => $d ) {
					if ( $d->isConfigured() ) {
						$any = true;
						printf( '<label><input type="checkbox" class="bbk-c-dest" value="%s" %s> %s%s</label>', esc_attr( $id ), checked( in_array( $id, (array) $s['destinations'], true ), true, false ), self::brand( $id, 18 ), esc_html( $d->label() ) );
					}
				}
				if ( ! $any ) {
					echo '<span class="description">' . wp_kses( sprintf( /* translators: %s: link */ __( 'Nowhere yet. <a href="%s">Set up Google Drive, OneDrive or a file server</a> to keep copies off this server.', 'bastion-backup' ), esc_url( self::url( 'destinations' ) ) ), array( 'a' => array( 'href' => true ) ) ) . '</span>';
				}
				echo '</fieldset>';
			},
			$actions
		);

		$free = Storage::freeSpace();
		self::panel(
			__( 'Backups', 'bastion-backup' ),
			esc_html(
				sprintf(
					/* translators: 1: schedule, 2: count here, 3: free space */
					__( '%1$s The newest %2$d are kept on this server.%3$s', 'bastion-backup' ),
					'none' === $s['schedule'] ? __( 'No backups are scheduled.', 'bastion-backup' ) : sprintf( /* translators: %s: how often */ __( 'A backup is made %s.', 'bastion-backup' ), self::scheduleWords( $s ) ),
					(int) $s['retain_local'],
					null !== $free ? ' ' . sprintf( /* translators: %s: size */ __( 'Free space: %s.', 'bastion-backup' ), size_format( (int) $free ) ) : ''
				)
			),
			static function (): void {
				echo '<table class="widefat striped bbk-list"><thead><tr><th scope="col">' . esc_html__( 'Made', 'bastion-backup' ) . '</th><th scope="col" class="num">' . esc_html__( 'Size', 'bastion-backup' ) . '</th><th scope="col">' . esc_html__( 'Kept on', 'bastion-backup' ) . '</th><th scope="col">' . esc_html__( 'Key', 'bastion-backup' ) . '</th><th scope="col">' . esc_html__( 'Check', 'bastion-backup' ) . '</th><th scope="col">' . esc_html__( 'Actions', 'bastion-backup' ) . '</th></tr></thead>';
				echo '<tbody id="bbk-rows"><tr><td colspan="6">' . esc_html__( 'Loading…', 'bastion-backup' ) . '</td></tr></tbody></table>';
			},
			'<button type="button" class="button" data-bbk="refresh">' . esc_html__( 'Refresh', 'bastion-backup' ) . '</button>'
		);

		self::panel(
			__( 'Restoring', 'bastion-backup' ),
			esc_html__( 'On this site from the Restore tab, or on a new server with the installer (like Duplicator\'s installer.php).', 'bastion-backup' ),
			static function (): void {
				echo '<ol class="bbk-steps">';
				echo '<li>' . esc_html__( 'Choose the backup: one in the list above, one on Google Drive, OneDrive or your file server, or a .bbk file from your computer.', 'bastion-backup' ) . '</li>';
				echo '<li>' . esc_html__( 'Paste the backup key and press Check. Every part of the backup is read and checked first; nothing on the site changes unless the whole backup is intact.', 'bastion-backup' ) . '</li>';
				echo '<li>' . esc_html__( 'See what the backup holds, choose the database, the files or both, and confirm with your WordPress password and the word RESTORE.', 'bastion-backup' ) . '</li>';
				echo '<li>' . esc_html__( 'Visitors see a short maintenance page while it runs. The database is swapped in all at once at the end, so the site is never half restored.', 'bastion-backup' ) . '</li>';
				echo '<li>' . wp_kses( sprintf( /* translators: %s: link */ __( 'Site gone or moving server? Use the <a href="%s">installer</a> with the backup file on the new server.', 'bastion-backup' ), esc_url( self::url( 'installer' ) ) ), array( 'a' => array( 'href' => true ) ) ) . '</li>';
				echo '</ol>';
			}
		);
	}

	private static function scheduleWords( array $s ): string {
		return match ( $s['schedule'] ) {
			'hourly'     => __( 'every hour', 'bastion-backup' ),
			'twicedaily' => __( 'twice a day', 'bastion-backup' ),
			'daily'      => sprintf( /* translators: %s: time */ __( 'every day at %s', 'bastion-backup' ), $s['schedule_time'] ),
			'weekly'     => sprintf( /* translators: %s: time */ __( 'every week at %s', 'bastion-backup' ), $s['schedule_time'] ),
			default      => '',
		};
	}

	private static function tabRestore(): void {
		$dfm = defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS;
		self::panel(
			__( '1. Choose the backup', 'bastion-backup' ),
			esc_html__( 'Pick it from the list of backups, or send a .bbk file from your computer. Large files go up in pieces, so any size works.', 'bastion-backup' ),
			static function (): void {
				echo '<p id="bbk-r-chosen" class="bbk-chosen">' . wp_kses( sprintf( /* translators: %s: link */ __( 'No backup chosen. Press Restore beside one on the <a href="%s">Backups</a> tab, or upload one:', 'bastion-backup' ), esc_url( self::url() ) ), array( 'a' => array( 'href' => true ) ) ) . '</p>';
				echo '<p><label for="bbk-r-file" class="screen-reader-text">' . esc_html__( 'Backup file', 'bastion-backup' ) . '</label><input type="file" id="bbk-r-file" accept=".bbk"> <button type="button" class="button" data-bbk="upload">' . esc_html__( 'Upload', 'bastion-backup' ) . '</button></p>';
				echo '<progress id="bbk-r-upbar" max="100" value="0" hidden></progress> <span id="bbk-r-upmsg" aria-live="polite"></span>';
			}
		);
		self::panel(
			__( '2. Check it with the key', 'bastion-backup' ),
			esc_html__( 'The key that was shown when the backup key was made (BBK-…). It is kept only in this job, encrypted, and forgotten when the job ends.', 'bastion-backup' ),
			static function (): void {
				echo '<form id="bbk-r-check" class="bbk-inline-form"><label for="bbk-r-key">' . esc_html__( 'Backup key', 'bastion-backup' ) . '</label> ';
				echo '<input type="password" id="bbk-r-key" class="large-text code" autocomplete="off" spellcheck="false" placeholder="BBK-…" required> ';
				echo '<button class="button button-primary" disabled>' . esc_html__( 'Check the backup', 'bastion-backup' ) . '</button></form>';
			}
		);
		self::panel(
			__( '3. Confirm and restore', 'bastion-backup' ),
			$dfm ? esc_html__( 'DISALLOW_FILE_MODS is set in wp-config.php, so only the database can be restored here. Use the installer for files.', 'bastion-backup' ) : esc_html__( 'Shown once the backup has been checked.', 'bastion-backup' ),
			static function () use ( $dfm ): void {
				echo '<div id="bbk-r-preview" hidden></div>';
				echo '<form id="bbk-r-confirm" hidden class="bbk-confirm">';
				printf( '<fieldset class="bbk-choices"><legend>%s</legend><label><input type="checkbox" id="bbk-r-db" checked> %s</label><label><input type="checkbox" id="bbk-r-files" %s> %s</label></fieldset>', esc_html__( 'Restore', 'bastion-backup' ), esc_html__( 'The database', 'bastion-backup' ), $dfm ? 'disabled' : 'checked', esc_html__( 'The files', 'bastion-backup' ) );
				echo '<p class="description">' . esc_html__( 'wp-config.php, this plugin and its backups are never overwritten here. After the restore you may need to sign in again.', 'bastion-backup' ) . '</p>';
				echo '<p><label for="bbk-r-pw">' . esc_html__( 'Your WordPress password', 'bastion-backup' ) . '</label><br><input type="password" id="bbk-r-pw" class="regular-text" autocomplete="current-password" required></p>';
				echo '<p><label for="bbk-r-word">' . esc_html__( 'Type RESTORE to confirm', 'bastion-backup' ) . '</label><br><input type="text" id="bbk-r-word" class="regular-text" autocomplete="off" required pattern="RESTORE"></p>';
				echo '<p><button class="button button-primary bbk-danger-primary">' . esc_html__( 'Restore now', 'bastion-backup' ) . '</button> <button type="button" class="button" data-bbk="cancel">' . esc_html__( 'Cancel', 'bastion-backup' ) . '</button></p>';
				echo '</form>';
			}
		);
	}

	private static function tabDestinations(): void {
		$redirect = OAuthDestination::redirectUri();
		foreach ( array( 'google', 'onedrive' ) as $id ) {
			/** @var OAuthDestination $d */
			$d     = Registry::get( $id );
			$c     = $d->config();
			$state = $d->isConnected() ? sprintf( /* translators: %s: account */ __( 'Connected%s.', 'bastion-backup' ), $d->account() ? ' ' . sprintf( __( 'as %s', 'bastion-backup' ), $d->account() ) : '' ) : __( 'Not connected.', 'bastion-backup' );
			self::panel(
				$d->label(),
				'<span class="bbk-badge ' . ( $d->isConnected() ? 'ok' : '' ) . '">' . esc_html( $state ) . '</span>',
				static function () use ( $d, $c, $id, $redirect ): void {
					$where = 'google' === $id ? __( 'Backups go into a folder in your Google Drive. The plugin can see only the files it puts there.', 'bastion-backup' ) : __( 'Backups go into Apps → Bastion Backup in your OneDrive. The plugin can see nothing else.', 'bastion-backup' );
					echo '<div class="bbk-dest-form" data-dest="' . esc_attr( $id ) . '">';
					if ( $d->hasShared() ) {
						echo '<p>' . esc_html( $where ) . '</p>';
						echo '<p class="bbk-buttons">';
						if ( ! $d->isConnected() ) {
							printf( '<button type="button" class="button button-hero bbk-connect" data-bbk="device">%s%s</button> ', self::brand( $id, 24 ), esc_html( sprintf( /* translators: %s: Google Drive or OneDrive */ __( 'Connect %s', 'bastion-backup' ), $d->label() ) ) );
						} else {
							echo '<button type="button" class="button" data-bbk="test">' . esc_html__( 'Test', 'bastion-backup' ) . '</button> ';
							echo '<button type="button" class="button bbk-danger" data-bbk="disconnect">' . esc_html__( 'Disconnect', 'bastion-backup' ) . '</button> ';
						}
						echo '<span class="bbk-result" aria-live="polite"></span></p>';
						echo '<div class="bbk-device" hidden></div>';
					}
					echo '</div>';
					echo '<details class="bbk-howto"' . ( $d->hasShared() || $d->hasApp() ? '' : ' open' ) . '><summary>' . esc_html( $d->hasShared() ? __( 'Advanced: use your own app instead', 'bastion-backup' ) : __( 'Set up with your own app (once)', 'bastion-backup' ) ) . '</summary><ol>';
					if ( 'google' === $id ) {
						echo '<li>' . wp_kses( __( 'Open <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">Google Cloud Console → Credentials</a> with the Google account that should hold the backups. Create a project if asked.', 'bastion-backup' ), array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ) . '</li>';
						echo '<li>' . esc_html__( 'Enable the Google Drive API (APIs & Services → Library → Google Drive API → Enable).', 'bastion-backup' ) . '</li>';
						echo '<li>' . esc_html__( 'OAuth consent screen: External (or Internal for Google Workspace), add your address as a test user, and publish it to Production so access does not expire after 7 days. Scope: …/auth/drive.file only.', 'bastion-backup' ) . '</li>';
						echo '<li>' . esc_html__( 'Create credentials → OAuth client ID → Web application. Under Authorized redirect URIs, paste the address below.', 'bastion-backup' ) . '</li>';
					} else {
						echo '<li>' . wp_kses( __( 'Open <a href="https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank" rel="noopener noreferrer">Microsoft Entra → App registrations</a> and press New registration.', 'bastion-backup' ), array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ) . '</li>';
						echo '<li>' . esc_html__( 'Supported account types: personal Microsoft accounts and/or your organisation. Redirect URI: Web, the address below.', 'bastion-backup' ) . '</li>';
						echo '<li>' . esc_html__( 'API permissions → Microsoft Graph → Delegated: Files.ReadWrite.AppFolder, offline_access, User.Read. Certificates & secrets → New client secret; copy its Value (it is shown once).', 'bastion-backup' ) . '</li>';
					}
					echo '<li>' . esc_html__( 'Paste the client ID and secret here, save, then press Connect with my app and allow access.', 'bastion-backup' ) . '</li></ol>';
					echo '<p class="bbk-copyline"><label>' . esc_html__( 'Redirect URI', 'bastion-backup' ) . ' <input type="text" readonly class="large-text code" value="' . esc_attr( $redirect ) . '"></label> <button type="button" class="button" data-bbk="copy">' . esc_html__( 'Copy', 'bastion-backup' ) . '</button></p>';
					echo '<form class="bbk-dest-form" data-dest="' . esc_attr( $id ) . '"><table class="form-table" role="presentation"><tbody>';
					printf( '<tr><th scope="row"><label for="bbk-%1$s-cid">%2$s</label></th><td><input id="bbk-%1$s-cid" name="client_id" class="regular-text code" value="%3$s" autocomplete="off"></td></tr>', esc_attr( $id ), esc_html__( 'Client ID', 'bastion-backup' ), esc_attr( (string) ( $c['client_id'] ?? '' ) ) );
					printf( '<tr><th scope="row"><label for="bbk-%1$s-sec">%2$s</label></th><td><input id="bbk-%1$s-sec" name="client_secret" type="password" class="regular-text code" autocomplete="new-password" placeholder="%3$s"></td></tr>', esc_attr( $id ), esc_html__( 'Client secret', 'bastion-backup' ), esc_attr( $d->hasApp() ? '•••••••• ' . __( 'saved — type to replace', 'bastion-backup' ) : '' ) );
					printf( '<tr><th scope="row"><label for="bbk-%1$s-folder">%2$s</label></th><td><input id="bbk-%1$s-folder" name="folder" class="regular-text" value="%3$s" placeholder="%4$s"></td></tr>', esc_attr( $id ), esc_html__( 'Folder', 'bastion-backup' ), esc_attr( (string) ( $c['folder'] ?? '' ) ), esc_attr( $d instanceof Destinations\GoogleDrive || $d instanceof Destinations\OneDrive ? $d->folderName() : '' ) );
					if ( 'onedrive' === $id ) {
						printf( '<tr><th scope="row"><label for="bbk-od-tenant">%s</label></th><td><input id="bbk-od-tenant" name="tenant" class="regular-text code" value="%s" placeholder="common"><p class="description">%s</p></td></tr>', esc_html__( 'Tenant', 'bastion-backup' ), esc_attr( (string) ( $c['tenant'] ?? '' ) ), esc_html__( 'common for any account, consumers for personal only, or your organisation\'s tenant ID.', 'bastion-backup' ) );
					}
					echo '</tbody></table><p class="bbk-buttons"><button class="button">' . esc_html__( 'Save', 'bastion-backup' ) . '</button> ';
					echo '<button type="button" class="button" data-bbk="connect"' . ( $d->hasApp() ? '' : ' disabled' ) . '>' . esc_html__( 'Connect with my app', 'bastion-backup' ) . '</button> ';
					if ( ! $d->hasShared() ) {
						echo '<button type="button" class="button" data-bbk="test"' . ( $d->isConfigured() ? '' : ' disabled' ) . '>' . esc_html__( 'Test', 'bastion-backup' ) . '</button> ';
						echo '<button type="button" class="button bbk-danger" data-bbk="disconnect"' . ( $d->isConnected() ? '' : ' disabled' ) . '>' . esc_html__( 'Disconnect', 'bastion-backup' ) . '</button> ';
					}
					echo '<span class="bbk-result" aria-live="polite"></span></p></form></details>';
				},
				'',
				'',
				self::brand( $id, 24 )
			);
		}
		$fs = new FileServer();
		$c  = $fs->config();
		self::panel(
			$fs->label(),
			'<span class="bbk-badge ' . ( $fs->isConfigured() ? 'ok' : '' ) . '">' . esc_html( $fs->isConfigured() ? __( 'Set up.', 'bastion-backup' ) : __( 'Not set up.', 'bastion-backup' ) ) . '</span> ' . esc_html__( 'Your own server or NAS, over SFTP (SSH) or FTPS. Plain FTP is not offered: it sends the password unencrypted.', 'bastion-backup' ),
			static function () use ( $fs, $c ): void {
				$s = $fs->hasSecret();
				echo '<form class="bbk-dest-form" data-dest="fileserver"><table class="form-table" role="presentation"><tbody>';
				echo '<tr><th scope="row"><label for="bbk-fs-proto">' . esc_html__( 'Connection', 'bastion-backup' ) . '</label></th><td><select id="bbk-fs-proto" name="protocol">';
				foreach ( array( 'sftp' => 'SFTP (SSH)', 'ftps' => __( 'FTPS (explicit TLS, port 21)', 'bastion-backup' ), 'ftps-implicit' => __( 'FTPS (implicit TLS, port 990)', 'bastion-backup' ) ) as $v => $l ) {
					printf( '<option value="%s" %s>%s</option>', esc_attr( $v ), selected( $c['protocol'], $v, false ), esc_html( $l ) );
				}
				echo '</select></td></tr>';
				printf( '<tr><th scope="row"><label for="bbk-fs-host">%s</label></th><td><input id="bbk-fs-host" name="host" class="regular-text code" value="%s" placeholder="backup.example.org"> <label>%s <input name="port" type="number" min="0" max="65535" class="small-text" value="%s" placeholder="22"></label></td></tr>', esc_html__( 'Server', 'bastion-backup' ), esc_attr( $c['host'] ), esc_html__( 'Port', 'bastion-backup' ), esc_attr( $c['port'] ? (string) $c['port'] : '' ) );
				printf( '<tr><th scope="row"><label for="bbk-fs-path">%s</label></th><td><input id="bbk-fs-path" name="path" class="regular-text code" value="%s" placeholder="backups/%s"><p class="description">%s</p></td></tr>', esc_html__( 'Folder', 'bastion-backup' ), esc_attr( $c['path'] ), esc_attr( Settings::siteSlug() ), esc_html__( 'Without a leading / it is inside the login\'s home folder. It is made if missing.', 'bastion-backup' ) );
				printf( '<tr><th scope="row"><label for="bbk-fs-user">%s</label></th><td><input id="bbk-fs-user" name="username" class="regular-text code" value="%s" autocomplete="off"></td></tr>', esc_html__( 'Login', 'bastion-backup' ), esc_attr( $c['username'] ) );
				printf( '<tr><th scope="row">%s</th><td><label><input type="radio" name="auth" value="password" %s> %s</label> &nbsp; <label><input type="radio" name="auth" value="key" %s> %s</label></td></tr>', esc_html__( 'Sign in with', 'bastion-backup' ), checked( $c['auth'], 'password', false ), esc_html__( 'Password', 'bastion-backup' ), checked( $c['auth'], 'key', false ), esc_html__( 'SSH private key (SFTP)', 'bastion-backup' ) );
				printf( '<tr class="bbk-auth-password"><th scope="row"><label for="bbk-fs-pw">%s</label></th><td><input id="bbk-fs-pw" name="password" type="password" class="regular-text" autocomplete="new-password" placeholder="%s"></td></tr>', esc_html__( 'Password', 'bastion-backup' ), esc_attr( $s && 'password' === $c['auth'] ? '•••••••• ' . __( 'saved — type to replace', 'bastion-backup' ) : '' ) );
				printf( '<tr class="bbk-auth-key"><th scope="row"><label for="bbk-fs-key">%s</label></th><td><textarea id="bbk-fs-key" name="private_key" rows="4" class="large-text code" placeholder="%s"></textarea><br><label>%s <input name="key_passphrase" type="password" class="regular-text" autocomplete="new-password"></label><p class="description">%s</p></td></tr>', esc_html__( 'Private key', 'bastion-backup' ), esc_attr( $s && 'key' === $c['auth'] ? '•••••••• ' . __( 'saved — paste to replace', 'bastion-backup' ) : '-----BEGIN OPENSSH PRIVATE KEY-----' ), esc_html__( 'Key passphrase (if any)', 'bastion-backup' ), esc_html__( 'Use a key made only for backups, allowed only into the backup folder.', 'bastion-backup' ) );
				printf( '<tr class="bbk-sftp-only"><th scope="row"><label for="bbk-fs-fp">%s</label></th><td><input id="bbk-fs-fp" name="fingerprint" class="large-text code" value="%s" placeholder="SHA256:…"><p class="description">%s <code>ssh-keyscan -t ed25519 %s | ssh-keygen -lf -</code></p></td></tr>', esc_html__( 'Host key fingerprint', 'bastion-backup' ), esc_attr( $c['fingerprint'] ), esc_html__( 'So a server pretending to be yours is refused. On a computer you trust, run:', 'bastion-backup' ), esc_html( $c['host'] ?: 'backup.example.org' ) );
				echo '</tbody></table><p class="bbk-buttons"><button class="button button-primary">' . esc_html__( 'Save', 'bastion-backup' ) . '</button> ';
				echo '<button type="button" class="button" data-bbk="test"' . ( $fs->isConfigured() ? '' : ' disabled' ) . '>' . esc_html__( 'Test', 'bastion-backup' ) . '</button> ';
				echo '<button type="button" class="button bbk-danger" data-bbk="disconnect"' . ( $s ? '' : ' disabled' ) . '>' . esc_html__( 'Remove', 'bastion-backup' ) . '</button> <span class="bbk-result" aria-live="polite"></span></p></form>';
			},
			'',
			'',
			self::brand( 'fileserver', 24 )
		);
	}

	private static function tabSchedule(): void {
		$s = Settings::get();
		echo '<form class="bbk-settings" data-section="schedule"><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="bbk-s-sched">' . esc_html__( 'Back up automatically', 'bastion-backup' ) . '</label></th><td><select id="bbk-s-sched" name="schedule">';
		foreach ( array( 'none' => __( 'Never (only when I press Back up now)', 'bastion-backup' ), 'hourly' => __( 'Every hour', 'bastion-backup' ), 'twicedaily' => __( 'Twice a day', 'bastion-backup' ), 'daily' => __( 'Every day', 'bastion-backup' ), 'weekly' => __( 'Every week', 'bastion-backup' ) ) as $v => $l ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $v ), selected( $s['schedule'], $v, false ), esc_html( $l ) );
		}
		printf( '</select> <label>%s <input type="time" name="schedule_time" value="%s"></label><p class="description">%s</p></td></tr>', esc_html__( 'at', 'bastion-backup' ), esc_attr( $s['schedule_time'] ), esc_html( sprintf( /* translators: %s: timezone */ __( 'Daily and weekly backups start at this time (%s). WordPress runs scheduled work when the site has visitors; for exact times, have the host call wp-cron.php every few minutes.', 'bastion-backup' ), wp_timezone_string() ) ) );
		printf( '<tr><th scope="row">%s</th><td><label><input type="checkbox" name="backup_db" %s> %s</label><br><label><input type="checkbox" name="backup_files" %s> %s</label></td></tr>', esc_html__( 'What', 'bastion-backup' ), checked( $s['backup_db'], true, false ), esc_html__( 'Database', 'bastion-backup' ), checked( $s['backup_files'], true, false ), esc_html__( 'Files', 'bastion-backup' ) );
		echo '<tr><th scope="row">' . esc_html__( 'Copy to', 'bastion-backup' ) . '</th><td>';
		foreach ( Registry::all() as $id => $d ) {
			printf( '<label><input type="checkbox" name="destinations[]" value="%s" %s %s> %s%s%s</label><br>', esc_attr( $id ), checked( in_array( $id, (array) $s['destinations'], true ), true, false ), disabled( ! $d->isConfigured() && ! in_array( $id, (array) $s['destinations'], true ), true, false ), self::brand( $id, 18 ), esc_html( $d->label() ), $d->isConfigured() ? '' : ' <span class="description">(' . esc_html__( 'not set up', 'bastion-backup' ) . ')</span>' );
		}
		echo '</td></tr>';
		printf( '<tr><th scope="row"><label for="bbk-s-rl">%s</label></th><td><input id="bbk-s-rl" name="retain_local" type="number" min="1" max="100" class="small-text" value="%d"> <label><input type="checkbox" name="keep_local" %s> %s</label></td></tr>', esc_html__( 'Keep on this server', 'bastion-backup' ), (int) $s['retain_local'], checked( $s['keep_local'], true, false ), esc_html__( 'Keep copies here at all (when off, a backup is deleted from this server once it is copied elsewhere)', 'bastion-backup' ) );
		printf( '<tr><th scope="row"><label for="bbk-s-rr">%s</label></th><td><input id="bbk-s-rr" name="retain_remote" type="number" min="1" max="365" class="small-text" value="%d"><p class="description">%s</p></td></tr>', esc_html__( 'Keep at each destination', 'bastion-backup' ), (int) $s['retain_remote'], esc_html__( 'Older ones are deleted after each backup. Only backups this plugin made are ever deleted.', 'bastion-backup' ) );
		echo '</tbody></table>';
		submit_button( __( 'Save', 'bastion-backup' ) );
		echo '</form>';
	}

	private static function tabSettings(): void {
		$s = Settings::get();
		echo '<form class="bbk-settings" data-section="settings"><table class="form-table" role="presentation"><tbody>';
		printf( '<tr><th scope="row"><label for="bbk-s-ex">%s</label></th><td><textarea id="bbk-s-ex" name="excludes" rows="8" class="large-text code">%s</textarea><p class="description">%s</p></td></tr>', esc_html__( 'Leave out', 'bastion-backup' ), esc_textarea( implode( "\n", (array) $s['excludes'] ) ), esc_html__( 'One per line: a path from the WordPress folder (wp-content/cache) or a pattern (*.log). This plugin\'s own backups are always left out.', 'bastion-backup' ) );
		printf( '<tr><th scope="row"><label for="bbk-s-mf">%s</label></th><td><input id="bbk-s-mf" name="max_file_mb" type="number" min="0" class="small-text" value="%d"> MB <p class="description">%s</p></td></tr>', esc_html__( 'Largest file', 'bastion-backup' ), (int) $s['max_file_mb'], esc_html__( '0 for no limit. Larger files are left out and listed in the log.', 'bastion-backup' ) );
		printf( '<tr><th scope="row">%s</th><td><label><input type="checkbox" name="all_tables" %s> %s</label><br><label for="bbk-s-xt">%s</label><br><textarea id="bbk-s-xt" name="exclude_tables" rows="3" class="regular-text code">%s</textarea></td></tr>', esc_html__( 'Database tables', 'bastion-backup' ), checked( $s['all_tables'], true, false ), esc_html( sprintf( /* translators: %s: prefix */ __( 'All tables in the database, not only those starting with %s', 'bastion-backup' ), $GLOBALS['table_prefix'] ?? 'wp_' ) ), esc_html__( 'Leave out these tables (one per line):', 'bastion-backup' ), esc_textarea( implode( "\n", (array) $s['exclude_tables'] ) ) );
		printf( '<tr><th scope="row"><label for="bbk-s-step">%s</label></th><td><input id="bbk-s-step" name="step_seconds" type="number" min="5" max="120" class="small-text" value="%d"> %s<p class="description">%s</p></td></tr>', esc_html__( 'Step length', 'bastion-backup' ), (int) $s['step_seconds'], esc_html__( 'seconds', 'bastion-backup' ), esc_html__( 'How long each step works before saving its place. Never more than 60% of PHP\'s time limit. Lower it if the host stops long requests.', 'bastion-backup' ) );
		printf( '<tr><th scope="row"><label for="bbk-s-mail">%s</label></th><td><input id="bbk-s-mail" name="notify_email" type="email" class="regular-text" value="%s"> <select name="notify_on">%s</select></td></tr>', esc_html__( 'Email', 'bastion-backup' ), esc_attr( $s['notify_email'] ), implode( '', array_map( static fn( $v, $l ) => sprintf( '<option value="%s" %s>%s</option>', esc_attr( $v ), selected( $s['notify_on'], $v, false ), esc_html( $l ) ), array( 'failure', 'always', 'never' ), array( __( 'when a backup fails or has warnings', 'bastion-backup' ), __( 'after every backup', 'bastion-backup' ), __( 'never', 'bastion-backup' ) ) ) ) );
		printf( '<tr><th scope="row">%s</th><td><label><input type="checkbox" name="delete_on_uninstall" %s> %s</label></td></tr>', esc_html__( 'Uninstalling', 'bastion-backup' ), checked( $s['delete_on_uninstall'], true, false ), esc_html__( 'Also delete the backups kept on this server when the plugin is deleted', 'bastion-backup' ) );
		echo '</tbody></table>';
		submit_button( __( 'Save', 'bastion-backup' ) );
		echo '</form>';
		self::panel(
			__( 'Server check', 'bastion-backup' ),
			esc_html__( 'What the PHP that runs this site actually has. A hosting panel can show settings for another PHP version or build.', 'bastion-backup' ),
			static function (): void {
				echo '<table class="widefat striped bbk-server"><tbody>';
				foreach ( ServerCheck::rows() as [ $label, $value, $state ] ) {
					$badge = array( 'ok' => '<span class="bbk-badge ok">' . esc_html__( 'OK', 'bastion-backup' ) . '</span>', 'warn' => '<span class="bbk-badge warn">' . esc_html__( 'Slow', 'bastion-backup' ) . '</span>', 'bad' => '<span class="bbk-badge bad">' . esc_html__( 'Needs fixing', 'bastion-backup' ) . '</span>' )[ $state ] ?? '';
					printf( '<tr><th scope="row">%s</th><td><code>%s</code></td><td>%s</td></tr>', esc_html( $label ), esc_html( $value ), $badge ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
				}
				echo '</tbody></table>';
				if ( ! Core\Crypto::native() ) {
					echo '<p class="description">' . esc_html( ServerCheck::sodiumAdvice() ) . '</p>';
				}
			},
			'',
			'bbk-server'
		);
		self::panel(
			__( 'Stronger setup (wp-config.php)', 'bastion-backup' ),
			'',
			static function (): void {
				$rows = array(
					array( 'BASTION_BACKUP_STORAGE', __( 'A folder outside the website for backups and working files, e.g. /home/account/bastion-backups.', 'bastion-backup' ), defined( 'BASTION_BACKUP_STORAGE' ) ),
					array( 'BASTION_BACKUP_SECRET', __( 'A long random value (64+ characters) that encrypts the saved Google, Microsoft and file server credentials, instead of the WordPress salts.', 'bastion-backup' ), defined( 'BASTION_BACKUP_SECRET' ) ),
				);
				echo '<table class="widefat striped"><tbody>';
				foreach ( $rows as [ $c, $why, $set ] ) {
					printf( '<tr><td><code>%s</code></td><td>%s</td><td>%s</td></tr>', esc_html( $c ), esc_html( $why ), $set ? '<span class="bbk-badge ok">' . esc_html__( 'Set', 'bastion-backup' ) . '</span>' : '<span class="bbk-badge">' . esc_html__( 'Not set', 'bastion-backup' ) . '</span>' );
				}
				echo '</tbody></table><p class="description">' . esc_html( sprintf( /* translators: %s: path */ __( 'Backups are kept in %s.', 'bastion-backup' ), Storage::root() ) ) . '</p>';
			}
		);
	}

	private static function tabInstaller(): void {
		$made = get_option( 'bastion_backup_installer', null );
		self::panel(
			__( 'Installer for a new server', 'bastion-backup' ),
			esc_html__( 'A single installer.php that restores a backup on any server with PHP 8.1 and a MySQL database, like Duplicator\'s. It is locked with an access key shown once, and deletes itself when it has finished.', 'bastion-backup' ),
			static function () use ( $made ): void {
				if ( is_array( $made ) ) {
					echo '<p>' . esc_html( sprintf( /* translators: %s: date */ __( 'The last installer was made %s. Making a new one makes the old access key useless.', 'bastion-backup' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $made['t'] ) ) ) . '</p>';
				}
				echo '<div id="bbk-inst-made" class="notice notice-success inline" hidden role="status"></div>';
				echo '<form id="bbk-inst-form" class="bbk-inline-form"><label for="bbk-inst-pw">' . esc_html__( 'Your WordPress password', 'bastion-backup' ) . '</label> <input type="password" id="bbk-inst-pw" class="regular-text" autocomplete="current-password" required> <button class="button button-primary">' . esc_html__( 'Make installer.php', 'bastion-backup' ) . '</button></form>';
			}
		);
		self::panel(
			__( 'Disaster recovery in 5 steps', 'bastion-backup' ),
			'',
			static function (): void {
				echo '<ol class="bbk-steps">';
				echo '<li>' . esc_html__( 'On the new server, make an empty database and a database user for it, and point the domain at an empty folder (HTTPS on).', 'bastion-backup' ) . '</li>';
				echo '<li>' . esc_html__( 'Upload installer.php and the .bbk backup file into that folder (downloaded from here, Google Drive, OneDrive or your file server).', 'bastion-backup' ) . '</li>';
				echo '<li>' . esc_html__( 'Open https://<your site>/installer.php and enter the installer\'s access key.', 'bastion-backup' ) . '</li>';
				echo '<li>' . esc_html__( 'Choose the backup, paste the backup key (BBK-…) and the new database details, press Test connection, then Check, then Restore. Nothing is written unless the whole backup is intact.', 'bastion-backup' ) . '</li>';
				echo '<li>' . esc_html__( 'Sign in to the restored site. The installer deletes itself and its working folder; if it says it could not, delete installer.php by hand straight away.', 'bastion-backup' ) . '</li>';
				echo '</ol>';
			}
		);
	}

	private static function tabLogs(): void {
		$rows = Audit::all();
		self::panel(
			__( 'Who did what', 'bastion-backup' ),
			esc_html__( 'Every backup, restore, key change, connection and deletion, newest first.', 'bastion-backup' ),
			static function () use ( $rows ): void {
				if ( ! $rows ) {
					echo '<p>' . esc_html__( 'Nothing yet.', 'bastion-backup' ) . '</p>';
					return;
				}
				echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'When', 'bastion-backup' ) . '</th><th scope="col">' . esc_html__( 'Who', 'bastion-backup' ) . '</th><th scope="col">' . esc_html__( 'What', 'bastion-backup' ) . '</th><th scope="col">' . esc_html__( 'Details', 'bastion-backup' ) . '</th><th scope="col">' . esc_html__( 'From', 'bastion-backup' ) . '</th></tr></thead><tbody>';
				foreach ( array_slice( $rows, 0, 200 ) as $r ) {
					printf( '<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td></tr>', esc_html( wp_date( 'Y-m-d H:i', (int) $r['t'] ) ), esc_html( (string) $r['who'] ), esc_html( (string) $r['what'] ), esc_html( (string) $r['info'] ), esc_html( (string) $r['ip'] ) );
				}
				echo '</tbody></table>';
			}
		);
		$cur = Jobs::current();
		self::panel(
			__( 'Latest job', 'bastion-backup' ),
			'',
			static function () use ( $cur ): void {
				if ( ! $cur ) {
					echo '<p>' . esc_html__( 'No job yet.', 'bastion-backup' ) . '</p>';
					return;
				}
				$lines = ( new Logger( (string) $cur['id'] ) )->tail( 200 );
				echo '<pre class="bbk-log">' . esc_html( implode( "\n", $lines ) ) . '</pre>';
				printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bbk_log&job=' . rawurlencode( (string) $cur['id'] ) ), 'bbk_log' ) ), esc_html__( 'Download the whole log', 'bastion-backup' ) );
			}
		);
	}
}
