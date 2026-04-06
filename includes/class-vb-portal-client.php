<?php
/**
 * Client for communicating with the WP Vibe website API.
 *
 * Handles API calls to the website for key validation,
 * model listing, announcements, and remote system prompt retrieval.
 *
 * @package WPVibe
 */

defined( 'ABSPATH' ) || exit;

class VB_Portal_Client {

	/**
	 * Default portal base URL.
	 */
	private const DEFAULT_PORTAL_URL = 'https://wpvibe.net';

	/**
	 * Cache TTL for models list (1 hour).
	 */
	private const MODELS_CACHE_TTL = 3600;

	/**
	 * Cache TTL for system prompt (6 hours).
	 */
	private const PROMPT_CACHE_TTL = 21600;

	/**
	 * Cache TTL for announcements (30 minutes).
	 */
	private const ANNOUNCEMENTS_CACHE_TTL = 1800;

	/**
	 * Get the portal base URL.
	 *
	 * @return string
	 */
	private function get_portal_url(): string {
		// Allow override via wp-config.php constant (useful for local dev).
		if ( defined( 'WPVIBE_PORTAL_URL' ) && '' !== WPVIBE_PORTAL_URL ) {
			return rtrim( WPVIBE_PORTAL_URL, '/' );
		}

		$custom_url = get_option( 'wpvibe_portal_url', '' );

		if ( '' !== $custom_url ) {
			if ( ! $this->is_safe_url( $custom_url ) ) {
				VB_Logger::instance()->warning( 'Portal URL failed safety validation, using default.' );
				return self::DEFAULT_PORTAL_URL;
			}

			$parsed_host  = wp_parse_url( $custom_url, PHP_URL_HOST );
			$default_host = wp_parse_url( self::DEFAULT_PORTAL_URL, PHP_URL_HOST );
			if ( $parsed_host !== $default_host ) {
				VB_Logger::instance()->info(
					'Using custom portal URL: ' . sanitize_text_field( $parsed_host )
					. ' — API key will be sent to this host.'
				);
			}

			return rtrim( $custom_url, '/' );
		}

		return self::DEFAULT_PORTAL_URL;
	}

	/**
	 * Validate a URL to prevent SSRF attacks.
	 *
	 * @param string $url URL to validate.
	 * @return bool Whether the URL is considered safe.
	 */
	private function is_safe_url( string $url ): bool {
		$parsed = wp_parse_url( $url );

		if ( ! $parsed || empty( $parsed['host'] ) ) {
			return false;
		}

		$scheme = strtolower( $parsed['scheme'] ?? '' );
		if ( 'https' !== $scheme ) {
			return false;
		}

		$host = strtolower( $parsed['host'] );

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' ), true ) ) {
			return false;
		}

		$ip = gethostbyname( $host );
		if ( $ip !== $host && ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		if ( str_contains( $host, '169.254.' ) || '169.254.169.254' === $ip ) {
			return false;
		}

		return true;
	}

	/**
	 * Make an authenticated request to the portal API.
	 *
	 * @param string $endpoint API endpoint path (e.g. '/api/plugin/validate-key').
	 * @param string $method   HTTP method ('GET' or 'POST').
	 * @param array  $body     Request body for POST requests.
	 * @param string $api_key  WPVibe service API key.
	 * @return array|WP_Error  Decoded JSON response or WP_Error.
	 */
	private function request( string $endpoint, string $method, array $body = array(), string $api_key = '' ): array|\WP_Error {
		$url = $this->get_portal_url() . $endpoint;

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'timeout' => 15,
		);

		if ( 'POST' === $method && ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code       = wp_remote_retrieve_response_code( $response );
		$body_text  = wp_remote_retrieve_body( $response );
		$data       = json_decode( $body_text, true );

		if ( null === $data ) {
			VB_Logger::instance()->warning( 'Portal returned non-JSON (HTTP ' . $code . '): ' . substr( $body_text, 0, 200 ) );
			return new \WP_Error( 'portal_parse_error', __( 'Invalid response from WPVibe portal.', 'wpvibe' ) );
		}

		if ( $code >= 400 ) {
			$message = $data['error'] ?? $data['message'] ?? __( 'Portal request failed.', 'wpvibe' );
			return new \WP_Error( 'portal_error', $message );
		}

		return $data;
	}

	/**
	 * Validate a WPVibe service key against the portal.
	 *
	 * @param string $api_key The vb_live_* or vb_test_* key.
	 * @return array{valid: bool, message: string, models: array, credits?: int}
	 */
	public function validate_key( string $api_key ): array {
		$result = $this->request( '/api/plugin/validate-key', 'POST', array(), $api_key );

		if ( is_wp_error( $result ) ) {
			return array(
				'valid'   => false,
				'message' => $result->get_error_message(),
				'models'  => array(),
			);
		}

		if ( empty( $result['valid'] ) ) {
			return array(
				'valid'   => false,
				'message' => $result['message'] ?? __( 'Invalid API key.', 'wpvibe' ),
				'models'  => array(),
			);
		}

		// Save proxy key for LiteLLM authentication.
		if ( ! empty( $result['proxy_key'] ) ) {
			$storage = new VB_Key_Storage();
			$storage->save_proxy_key( $result['proxy_key'] );
		}

		return array(
			'valid'   => true,
			'message' => __( 'WPVibe service key validated.', 'wpvibe' ),
			'models'  => $this->normalize_models( $result['models'] ?? array() ),
			'credits' => $result['credits'] ?? 0,
		);
	}

	/**
	 * Fetch available models for the current service key.
	 *
	 * Results are cached in a transient for MODELS_CACHE_TTL seconds.
	 *
	 * @param string $api_key The vb_live_* or vb_test_* key.
	 * @return array List of model objects.
	 */
	public function get_models( string $api_key ): array {
		$cache_key = 'wpvibe_portal_models';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$result = $this->request( '/api/plugin/models', 'GET', array(), $api_key );

		if ( is_wp_error( $result ) ) {
			return array();
		}

		$models = $result['models'] ?? array();
		set_transient( $cache_key, $models, self::MODELS_CACHE_TTL );

		return $models;
	}

	/**
	 * Fetch the active system prompt from the portal.
	 *
	 * Returns null if no remote prompt is set or the request fails.
	 * Cached in a transient for PROMPT_CACHE_TTL seconds.
	 *
	 * @return string|null The system prompt content, or null.
	 */
	public function get_system_prompt(): ?string {
		$cache_key = 'wpvibe_remote_system_prompt';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return '' === $cached ? null : $cached;
		}

		$result = $this->request( '/api/plugin/system-prompt', 'GET' );

		if ( is_wp_error( $result ) || empty( $result['content'] ) ) {
			// Cache the miss so we don't hit the portal on every request.
			set_transient( $cache_key, '', self::PROMPT_CACHE_TTL );
			return null;
		}

		set_transient( $cache_key, $result['content'], self::PROMPT_CACHE_TTL );

		return $result['content'];
	}

	/**
	 * Fetch active announcements from the portal.
	 *
	 * Cached in a transient for ANNOUNCEMENTS_CACHE_TTL seconds.
	 *
	 * @return array List of announcement objects.
	 */
	public function get_announcements(): array {
		$cache_key = 'wpvibe_portal_announcements';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$result = $this->request( '/api/plugin/announcements', 'GET' );

		if ( is_wp_error( $result ) ) {
			return array();
		}

		$announcements = $result['announcements'] ?? array();
		set_transient( $cache_key, $announcements, self::ANNOUNCEMENTS_CACHE_TTL );

		return $announcements;
	}

	/**
	 * Normalize model data from the portal.
	 *
	 * Handles both string arrays (e.g. ['claude-opus-4-6']) and
	 * object arrays (e.g. [{id: 'claude-opus-4-6', name: '...'}]).
	 *
	 * @param array $models Raw models from portal response.
	 * @return array Normalized model objects.
	 */
	private function normalize_models( array $models ): array {
		return array_map( function ( $m ) {
			if ( is_string( $m ) ) {
				return array(
					'id'          => $m,
					'name'        => ucwords( str_replace( array( '-', '.' ), array( ' ', '.' ), $m ) ),
					'description' => '',
					'recommended' => false,
				);
			}
			return array(
				'id'          => $m['id'] ?? $m['name'] ?? '',
				'name'        => $m['name'] ?? $m['id'] ?? '',
				'description' => $m['description'] ?? '',
				'recommended' => ! empty( $m['recommended'] ),
			);
		}, $models );
	}

	/**
	 * Report BYOK usage to the portal for audit tracking.
	 *
	 * @param string $api_key     The vb_live_* key.
	 * @param string $model       Model name used.
	 * @param int    $input_tokens  Input token count.
	 * @param int    $output_tokens Output token count.
	 */
	public function report_usage( string $api_key, string $model, int $input_tokens, int $output_tokens ): void {
		$this->request( '/api/plugin/usage', 'POST', array(
			'model'        => $model,
			'inputTokens'  => $input_tokens,
			'outputTokens' => $output_tokens,
		), $api_key );
	}
}
