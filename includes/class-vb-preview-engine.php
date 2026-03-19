<?php
/**
 * Live theme preview engine for the editor iFrame.
 *
 * Uses token-based authentication to temporarily switch the active theme
 * for a single request, so the preview iframe renders the actual WordPress
 * site with all JS, CSS, and PHP working correctly.
 *
 * @package WPVibe
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class VB_Preview_Engine {

	/**
	 * Transient expiry time in seconds (1 hour).
	 */
	const TOKEN_EXPIRY = 3600;

	/**
	 * Prefix for preview transient keys.
	 */
	const TOKEN_PREFIX = 'vb_preview_';

	/**
	 * Query parameter used in preview URLs.
	 */
	const QUERY_PARAM = 'vb_preview';

	/**
	 * Create a preview token for a theme slug.
	 *
	 * @param int    $version_id   Theme version ID.
	 * @param string $theme_slug   The theme directory name.
	 * @param string $preview_html Fallback static HTML (kept for backwards compat).
	 * @return string 32-character hex token.
	 */
	public function create_preview_token( int $version_id, string $theme_slug, string $preview_html = '' ): string {
		$token = bin2hex( random_bytes( 16 ) );

		$data = wp_json_encode( array(
			'version_id'   => $version_id,
			'theme_slug'   => $theme_slug,
			'preview_html' => $preview_html,
		) );

		set_transient( self::TOKEN_PREFIX . $token, $data, self::TOKEN_EXPIRY );

		return $token;
	}

	/**
	 * Validate a preview token.
	 *
	 * @param string $token Preview token to validate.
	 * @return array|null Token payload or null if invalid/expired.
	 */
	public function validate_token( string $token ): ?array {
		$token = strtolower( preg_replace( '/[^a-fA-F0-9]/', '', $token ) );

		if ( strlen( $token ) !== 32 ) {
			return null;
		}

		$raw = get_transient( self::TOKEN_PREFIX . $token );

		if ( empty( $raw ) ) {
			return null;
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) || ! isset( $data['version_id'] ) ) {
			return null;
		}

		return $data;
	}

	/**
	 * Build the preview URL for the iframe.
	 *
	 * Points to the site home with a preview token query parameter.
	 * The `template_include` filter will intercept this and switch the theme.
	 *
	 * @param string $token Preview token.
	 * @return string Full preview URL.
	 */
	public function get_preview_url( string $token ): string {
		return add_query_arg( self::QUERY_PARAM, $token, home_url( '/' ) );
	}

	/**
	 * Register the theme-switch hook.
	 *
	 * Must be called early (e.g. in the plugin's run() method) so the
	 * filters are in place before WordPress resolves the template.
	 */
	public function register_preview_hooks(): void {
		// Switch theme on frontend requests that carry a valid preview token.
		add_filter( 'template', array( $this, 'filter_template' ), 999 );
		add_filter( 'stylesheet', array( $this, 'filter_template' ), 999 );

		// Hide the WP admin bar in preview iframes.
		add_action( 'template_redirect', array( $this, 'maybe_hide_admin_bar' ) );

		// Inject a small toolbar at the top of the preview so users know it's a preview.
		add_action( 'wp_footer', array( $this, 'maybe_inject_preview_badge' ) );

		// Prevent the preview from being indexed.
		add_action( 'wp_head', array( $this, 'maybe_noindex' ) );
	}

	/**
	 * Filter the active theme to the preview theme if a valid token is present.
	 *
	 * @param string $theme Current theme slug.
	 * @return string Possibly overridden theme slug.
	 */
	public function filter_template( string $theme ): string {
		// Only run on frontend requests, not admin/REST/AJAX.
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $theme;
		}

		$token = sanitize_text_field( $_GET[ self::QUERY_PARAM ] ?? '' );

		if ( '' === $token ) {
			return $theme;
		}

		$data = $this->validate_token( $token );

		if ( null === $data || empty( $data['theme_slug'] ) ) {
			return $theme;
		}

		$preview_slug = $data['theme_slug'];

		// Verify the theme directory exists.
		if ( ! is_dir( get_theme_root() . '/' . $preview_slug ) ) {
			return $theme;
		}

		return $preview_slug;
	}

	/**
	 * Hide the WordPress admin bar on preview requests.
	 */
	public function maybe_hide_admin_bar(): void {
		if ( empty( $_GET[ self::QUERY_PARAM ] ) ) {
			return;
		}

		show_admin_bar( false );
	}

	/**
	 * Add a small "Preview" badge in the footer so users know it's not the live site.
	 */
	public function maybe_inject_preview_badge(): void {
		if ( empty( $_GET[ self::QUERY_PARAM ] ) ) {
			return;
		}

		echo '<div style="position:fixed;bottom:8px;right:8px;background:#4f46e5;color:#fff;font-size:11px;font-family:system-ui,sans-serif;padding:4px 10px;border-radius:4px;z-index:999999;opacity:0.85;pointer-events:none;">WP Vibe Preview</div>';
	}

	/**
	 * Prevent search engines from indexing preview pages.
	 */
	public function maybe_noindex(): void {
		if ( empty( $_GET[ self::QUERY_PARAM ] ) ) {
			return;
		}

		echo '<meta name="robots" content="noindex, nofollow">' . "\n";
	}
}
