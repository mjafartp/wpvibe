<?php
/**
 * Logger utility with daily rotation and API key sanitization.
 */

defined( 'ABSPATH' ) || exit;

class VB_Logger {

	private static ?VB_Logger $instance = null;
	private string $log_dir;

	private function __construct() {
		$upload_dir    = wp_upload_dir();
		$this->log_dir = $upload_dir['basedir'] . '/wpvibe/logs';
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function debug( string $message ): void {
		$this->log( 'DEBUG', $message );
	}

	public function info( string $message ): void {
		$this->log( 'INFO', $message );
	}

	public function warning( string $message ): void {
		$this->log( 'WARNING', $message );
	}

	public function error( string $message, ?\Throwable $e = null ): void {
		if ( $e ) {
			$message .= ' | Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
		}
		$this->log( 'ERROR', $message );
	}

	private function log( string $level, string $message ): void {
		$message   = $this->sanitize_for_log( $message );
		$timestamp = gmdate( 'Y-m-d H:i:s' );
		$line      = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;

		$log_file = $this->log_dir . '/wpvibe-' . gmdate( 'Y-m-d' ) . '.log';

		// Append to log file.
		if ( is_dir( $this->log_dir ) && is_writable( $this->log_dir ) ) {
			file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );
		}

		// Also log to PHP error log if WP_DEBUG is on.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( "[WPVibe] [{$level}] {$message}" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Strip API key patterns from log messages.
	 */
	private function sanitize_for_log( string $text ): string {
		// WPVibe service keys.
		$text = preg_replace( '/vb_(live|test)_[a-zA-Z0-9]{8,}/', 'vb_$1_***REDACTED***', $text );
		// Anthropic keys.
		$text = preg_replace( '/sk-ant-[a-zA-Z0-9\-]{8,}/', 'sk-ant-***REDACTED***', $text );
		// OpenAI keys.
		$text = preg_replace( '/sk-[a-zA-Z0-9]{8,}/', 'sk-***REDACTED***', $text );
		// Generic long tokens (OAuth, JWT).
		$text = preg_replace( '/eyJ[a-zA-Z0-9\-_]{20,}\.eyJ[a-zA-Z0-9\-_]{20,}\.[a-zA-Z0-9\-_]{20,}/', '***JWT_REDACTED***', $text );

		return $text;
	}
}
