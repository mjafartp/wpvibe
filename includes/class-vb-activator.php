<?php
/**
 * Plugin activation handler.
 */

defined( 'ABSPATH' ) || exit;

class VB_Activator {

	public static function activate(): void {
		self::create_tables();
		self::set_defaults();
		self::create_directories();
		self::schedule_crons();
	}

	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = array();

		// Chat sessions.
		$sql[] = "CREATE TABLE {$wpdb->prefix}wpvibe_sessions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			session_name VARCHAR(255) DEFAULT 'Untitled Theme',
			theme_slug VARCHAR(100) DEFAULT NULL,
			model_used VARCHAR(100) DEFAULT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) $charset_collate;";

		// Chat messages.
		$sql[] = "CREATE TABLE {$wpdb->prefix}wpvibe_messages (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NOT NULL,
			role ENUM('user', 'assistant', 'system') NOT NULL,
			content LONGTEXT NOT NULL,
			attachments JSON DEFAULT NULL,
			token_count INT UNSIGNED DEFAULT 0,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY session_id (session_id)
		) $charset_collate;";

		// Theme versions.
		$sql[] = "CREATE TABLE {$wpdb->prefix}wpvibe_theme_versions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NOT NULL,
			version_number INT UNSIGNED NOT NULL,
			theme_slug VARCHAR(100) NOT NULL,
			files_snapshot LONGTEXT DEFAULT NULL,
			message_id BIGINT UNSIGNED DEFAULT NULL,
			applied_at DATETIME DEFAULT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY session_id (session_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( $sql as $query ) {
			dbDelta( $query );
		}
	}

	private static function set_defaults(): void {
		add_option( 'wpvibe_db_version', '1.0.0' );
		add_option( 'wpvibe_onboarding_complete', false );
	}

	private static function create_directories(): void {
		$upload_dir = wp_upload_dir();
		$base_dir   = $upload_dir['basedir'] . '/wpvibe';

		$dirs = array(
			$base_dir . '/references',
			$base_dir . '/exports',
			$base_dir . '/logs',
		);

		foreach ( $dirs as $dir ) {
			wp_mkdir_p( $dir );
		}

		// Protect logs directory from web access.
		$htaccess = $base_dir . '/logs/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}

		// Also add an index.php for servers that ignore .htaccess.
		$index = $base_dir . '/logs/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	private static function schedule_crons(): void {
		if ( ! wp_next_scheduled( 'wpvibe_cleanup_images' ) ) {
			wp_schedule_event( time(), 'daily', 'wpvibe_cleanup_images' );
		}
	}
}
