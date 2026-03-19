<?php
/**
 * API key type detection and validation.
 */

defined( 'ABSPATH' ) || exit;

class VB_Key_Manager {

	private VB_Key_Storage $storage;
	private VB_Portal_Client $portal;

	public function __construct( VB_Key_Storage $storage ) {
		$this->storage = $storage;
		$this->portal  = new VB_Portal_Client();
	}

	/**
	 * Detect the type of API key based on its prefix/format.
	 */
	public function detect_key_type( string $key ): string {
		$key = trim( $key );

		if ( str_starts_with( $key, 'vb_live_' ) || str_starts_with( $key, 'vb_test_' ) ) {
			return 'wpvibe_service';
		}

		// sk-ant-oat* tokens are Claude OAuth Access Tokens.
		if ( str_starts_with( $key, 'sk-ant-oat' ) ) {
			return 'claude_oauth';
		}

		if ( str_starts_with( $key, 'sk-ant-' ) ) {
			return 'claude_api';
		}

		if ( str_starts_with( $key, 'sk-' ) && ! str_starts_with( $key, 'sk-ant-' ) ) {
			return 'openai_codex';
		}

		if ( $this->is_oauth_token( $key ) ) {
			return 'claude_oauth';
		}

		return 'unknown';
	}

	/**
	 * Validate an API key against its provider.
	 *
	 * @return array{valid: bool, message: string, models: array}
	 */
	public function validate_key( string $key, string $type ): array {
		return match ( $type ) {
			'wpvibe_service'  => $this->validate_wpvibe_key( $key ),
			'claude_api'           => $this->validate_anthropic_key( $key ),
			'openai_codex'         => $this->validate_openai_key( $key ),
			'claude_oauth'         => $this->validate_oauth_token( $key ),
			default                => array(
				'valid'   => false,
				'message' => __( 'Unknown API key format.', 'wpvibe' ),
				'models'  => array(),
			),
		};
	}

	/**
	 * Get available models for a given key type.
	 */
	public function get_available_models( string $key_type ): array {
		return match ( $key_type ) {
			'claude_api', 'claude_oauth' => array(
				array(
					'id'          => 'claude-sonnet-4-6',
					'name'        => 'Claude Sonnet 4.6',
					'description' => 'Best balance of speed and quality',
					'recommended' => true,
				),
				array(
					'id'          => 'claude-sonnet-4-5',
					'name'        => 'Claude Sonnet 4.5',
					'description' => 'Fast and capable',
					'recommended' => false,
				),
				array(
					'id'          => 'claude-opus-4-6',
					'name'        => 'Claude Opus 4.6',
					'description' => 'Highest quality, slower',
					'recommended' => false,
				),
				array(
					'id'          => 'claude-haiku-4-5',
					'name'        => 'Claude Haiku 4.5',
					'description' => 'Fastest model, lightweight tasks',
					'recommended' => false,
				),
			),
			'openai_codex' => array(
				array(
					'id'          => 'gpt-5.3-codex',
					'name'        => 'GPT 5.3 Codex',
					'description' => 'Best for code generation (reasoning model)',
					'recommended' => true,
				),
				array(
					'id'          => 'gpt-5.4',
					'name'        => 'GPT 5.4',
					'description' => 'Most capable OpenAI model',
					'recommended' => false,
				),
				array(
					'id'          => 'gpt-5.1',
					'name'        => 'GPT 5.1',
					'description' => 'Balanced performance',
					'recommended' => false,
				),
				array(
					'id'          => 'gpt-5-mini',
					'name'        => 'GPT 5 Mini',
					'description' => 'Lightweight and quick',
					'recommended' => false,
				),
			),
			'wpvibe_service' => $this->get_portal_models(),
			default => array(),
		};
	}

	/**
	 * Fetch models from the Service Portal for wpvibe_service keys.
	 *
	 * Falls back to a static default list if the portal is unreachable.
	 *
	 * @return array
	 */
	private function get_portal_models(): array {
		$api_key = $this->storage->get_key();
		if ( empty( $api_key ) ) {
			return $this->get_fallback_models();
		}

		$models = $this->portal->get_models( $api_key );
		if ( empty( $models ) ) {
			return $this->get_fallback_models();
		}

		// Normalize portal models to plugin format.
		return array_map( function ( $m ) {
			return array(
				'id'          => $m['id'] ?? $m['name'] ?? '',
				'name'        => $m['name'] ?? $m['id'] ?? '',
				'description' => $m['description'] ?? '',
				'recommended' => ! empty( $m['recommended'] ),
			);
		}, $models );
	}

	/**
	 * Static fallback models when the portal is unreachable.
	 */
	private function get_fallback_models(): array {
		return array(
			array(
				'id'          => 'claude-sonnet-fast',
				'name'        => 'Claude Sonnet (Fast)',
				'description' => 'Recommended for most tasks',
				'recommended' => true,
			),
			array(
				'id'          => 'gpt-5.4-latest',
				'name'        => 'GPT-5-4',
				'description' => 'OpenAI alternative',
				'recommended' => false,
			),
		);
	}

	private function is_oauth_token( string $key ): bool {
		// OAuth/JWT tokens are typically long and contain dots.
		return strlen( $key ) > 100 && str_contains( $key, '.' );
	}

	private function validate_wpvibe_key( string $key ): array {
		if ( strlen( $key ) < 20 ) {
			return array(
				'valid'   => false,
				'message' => __( 'Invalid WPVibe service key format.', 'wpvibe' ),
				'models'  => array(),
			);
		}

		// Validate against the WPVibe Service Portal.
		$result = $this->portal->validate_key( $key );

		if ( ! $result['valid'] ) {
			return $result;
		}

		// Cache credits for display.
		if ( isset( $result['credits'] ) ) {
			update_option( 'wpvibe_service_credits', (int) $result['credits'], false );
		}

		return $result;
	}

	private function validate_anthropic_key( string $key ): array {
		$logger = VB_Logger::instance();
		$logger->debug( 'Validating Anthropic key — length: ' . strlen( $key ) . ', prefix: ' . substr( $key, 0, 4 ) . '****' );

		// First try standard x-api-key authentication.
		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $key,
					'anthropic-version'  => '2023-06-01',
				),
				'body'    => wp_json_encode( array(
					'model'      => 'claude-sonnet-4-6',
					'max_tokens' => 1,
					'messages'   => array(
						array( 'role' => 'user', 'content' => 'Hi' ),
					),
				) ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'Connection failed: ', 'wpvibe' ) . $response->get_error_message(),
				'models'  => array(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$logger->debug( "x-api-key validation: HTTP {$code}" );

		if ( 401 === $code || 403 === $code ) {
			// x-api-key failed — try as a Bearer/session token (Claude setup token).
			$logger->debug( 'x-api-key auth failed, trying Bearer token...' );
			$bearer_result = $this->validate_anthropic_bearer( $key );
			if ( $bearer_result['valid'] ) {
				// Re-save as claude_oauth type so the AI router uses Bearer auth.
				$this->storage->save_key( $key, 'claude_oauth' );
				return $bearer_result;
			}

			return array(
				'valid'   => false,
				'message' => __( 'Invalid Anthropic API key or session token.', 'wpvibe' ),
				'models'  => array(),
			);
		}

		// 200 or 400 (bad request but authenticated) means the key is valid.
		return array(
			'valid'   => true,
			'message' => __( 'Anthropic API key validated successfully.', 'wpvibe' ),
			'models'  => $this->get_available_models( 'claude_api' ),
		);
	}

	private function validate_openai_key( string $key ): array {
		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'Connection failed: ', 'wpvibe' ) . $response->get_error_message(),
				'models'  => array(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 401 === $code ) {
			return array(
				'valid'   => false,
				'message' => __( 'Invalid OpenAI API key.', 'wpvibe' ),
				'models'  => array(),
			);
		}

		return array(
			'valid'   => true,
			'message' => __( 'OpenAI API key validated successfully.', 'wpvibe' ),
			'models'  => $this->get_available_models( 'openai_codex' ),
		);
	}

	/**
	 * Try validating an sk-ant-* key as a Bearer / session token.
	 * Claude setup tokens and session keys use Authorization: Bearer.
	 */
	private function validate_anthropic_bearer( string $key ): array {
		$logger = VB_Logger::instance();

		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'headers' => array(
					'Content-Type'      => 'application/json',
					'Authorization'     => 'Bearer ' . $key,
					'anthropic-version' => '2023-06-01',
					'anthropic-beta'    => 'oauth-2025-04-20',
				),
				'body'    => wp_json_encode( array(
					'model'      => 'claude-sonnet-4-6',
					'max_tokens' => 1,
					'messages'   => array(
						array( 'role' => 'user', 'content' => 'Hi' ),
					),
				) ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			$logger->debug( 'Bearer validation WP error: ' . $response->get_error_message() );
			return array(
				'valid'   => false,
				'message' => __( 'Connection failed: ', 'wpvibe' ) . $response->get_error_message(),
				'models'  => array(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$logger->debug( "Bearer validation response: HTTP {$code} — {$body}" );

		if ( 401 === $code || 403 === $code ) {
			return array(
				'valid'   => false,
				'message' => __( 'Invalid token.', 'wpvibe' ),
				'models'  => array(),
			);
		}

		// 200 or 400 (authenticated but bad request) means the token works.
		return array(
			'valid'   => true,
			'message' => __( 'Claude session token validated successfully.', 'wpvibe' ),
			'models'  => $this->get_available_models( 'claude_oauth' ),
		);
	}

	private function validate_oauth_token( string $key ): array {
		$logger = VB_Logger::instance();

		// sk-ant-oat tokens — Anthropic OAuth Access Tokens.
		if ( str_starts_with( $key, 'sk-ant-oat' ) ) {
			if ( strlen( $key ) < 20 ) {
				return array(
					'valid'   => false,
					'message' => __( 'OAuth token is too short.', 'wpvibe' ),
					'models'  => array(),
				);
			}

			$logger->debug( 'Validating OAT token — length: ' . strlen( $key ) );

			// OAT tokens require Bearer auth + oauth beta header.
			$response = wp_remote_post(
				'https://api.anthropic.com/v1/messages',
				array(
					'headers' => array(
						'Content-Type'      => 'application/json',
						'Authorization'     => 'Bearer ' . $key,
						'anthropic-version' => '2023-06-01',
						'anthropic-beta'    => 'oauth-2025-04-20',
					),
					'body'    => wp_json_encode( array(
						'model'      => 'claude-sonnet-4-6',
						'max_tokens' => 1,
						'messages'   => array(
							array( 'role' => 'user', 'content' => 'Hi' ),
						),
					) ),
					'timeout' => 15,
				)
			);

			if ( is_wp_error( $response ) ) {
				$logger->debug( 'OAT validation WP error: ' . $response->get_error_message() );
				// Accept on format if network fails.
				return array(
					'valid'   => true,
					'message' => __( 'Claude OAuth token saved (could not verify with API).', 'wpvibe' ),
					'models'  => $this->get_available_models( 'claude_oauth' ),
				);
			}

			$code     = wp_remote_retrieve_response_code( $response );
			$body_raw = wp_remote_retrieve_body( $response );
			$logger->debug( "OAT validation: HTTP {$code}" );

			// 200, 400 (authenticated but bad request), or 429 (rate limited) = valid.
			if ( in_array( $code, array( 200, 400, 429 ), true ) ) {
				return array(
					'valid'   => true,
					'message' => __( 'Claude OAuth token validated successfully.', 'wpvibe' ),
					'models'  => $this->get_available_models( 'claude_oauth' ),
				);
			}

			if ( 401 === $code || 403 === $code ) {
				$body_json = json_decode( $body_raw, true );
				$detail    = $body_json['error']['message'] ?? '';
				$logger->debug( "OAT rejected: {$detail}" );

				return array(
					'valid'   => false,
					'message' => $detail
						? sprintf( __( 'Token rejected: %s', 'wpvibe' ), $detail )
						: __( 'Invalid Claude OAuth token.', 'wpvibe' ),
					'models'  => array(),
				);
			}

			// Other status — accept on format.
			return array(
				'valid'   => true,
				'message' => __( 'Claude OAuth token saved.', 'wpvibe' ),
				'models'  => $this->get_available_models( 'claude_oauth' ),
			);
		}

		// JWT-based OAuth tokens — validate structure first.
		$parts = explode( '.', $key );
		if ( count( $parts ) !== 3 ) {
			return array(
				'valid'   => false,
				'message' => __( 'Invalid OAuth token format.', 'wpvibe' ),
				'models'  => array(),
			);
		}

		$payload = json_decode( base64_decode( $parts[1], true ), true );
		if ( is_array( $payload ) && isset( $payload['exp'] ) && $payload['exp'] < time() ) {
			return array(
				'valid'   => false,
				'message' => __( 'OAuth token has expired.', 'wpvibe' ),
				'models'  => array(),
			);
		}

		// Never trust JWT structure alone — always verify against the API.
		$jwt_response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'headers' => array(
					'Content-Type'      => 'application/json',
					'Authorization'     => 'Bearer ' . $key,
					'anthropic-version' => '2023-06-01',
				),
				'body'    => wp_json_encode( array(
					'model'      => 'claude-sonnet-4-6',
					'max_tokens' => 1,
					'messages'   => array(
						array( 'role' => 'user', 'content' => 'Hi' ),
					),
				) ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $jwt_response ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'Could not verify OAuth token with Anthropic API.', 'wpvibe' ),
				'models'  => array(),
			);
		}

		$jwt_code = wp_remote_retrieve_response_code( $jwt_response );

		// 200, 400 (bad request but authenticated), 429 (rate limited) = valid token.
		if ( in_array( $jwt_code, array( 200, 400, 429 ), true ) ) {
			return array(
				'valid'   => true,
				'message' => __( 'OAuth token validated successfully.', 'wpvibe' ),
				'models'  => $this->get_available_models( 'claude_oauth' ),
			);
		}

		return array(
			'valid'   => false,
			'message' => __( 'OAuth token rejected by Anthropic API.', 'wpvibe' ),
			'models'  => array(),
		);
	}
}
