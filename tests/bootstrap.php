<?php
/**
 * PHPUnit bootstrap file for WPVibe tests.
 *
 * Provides minimal stubs for WordPress functions so that unit tests
 * can run without a full WordPress installation.
 *
 * @package WPVibe
 */

// Simulate ABSPATH so files can be loaded.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'WPVIBE_PLUGIN_DIR' ) ) {
	define( 'WPVIBE_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'WPVIBE_VERSION' ) ) {
	define( 'WPVIBE_VERSION', '1.0.0' );
}

// WordPress auth constants (used by key encryption).
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'test-auth-key-for-phpunit-only' );
}
if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
	define( 'SECURE_AUTH_KEY', 'test-secure-auth-key-for-phpunit-only' );
}

// ── Minimal WordPress function stubs ──────────────────────────────────────

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( string $data ): string {
		return $data; // Simplified stub — real function strips unsafe tags.
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $target ): bool {
		if ( is_dir( $target ) ) {
			return true;
		}
		return mkdir( $target, 0755, true );
	}
}

if ( ! function_exists( 'get_theme_root' ) ) {
	function get_theme_root(): string {
		return sys_get_temp_dir() . '/wpvibe-test-themes';
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		return $value;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		$title = strtolower( $title );
		$title = preg_replace( '/[^a-z0-9\-]/', '-', $title );
		$title = preg_replace( '/-+/', '-', $title );
		return trim( $title, '-' );
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $expiry = 0 ): bool {
		$GLOBALS['_wp_transients'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		return $GLOBALS['_wp_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['_wp_transients'][ $key ] );
		return true;
	}
}

// Load classes under test.
require_once WPVIBE_PLUGIN_DIR . 'includes/class-vb-theme-parser.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-vb-theme-writer.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-vb-key-storage.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-vb-preview-engine.php';
