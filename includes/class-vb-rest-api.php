<?php
/**
 * WordPress REST API endpoints for WPVibe.
 *
 * Registers all REST routes consumed by the React front-end:
 *   - POST /validate-key        — Validate and save an API key.
 *   - GET  /models              — List models for the active key type.
 *   - POST /save-settings       — Persist editor/onboarding settings.
 *   - POST /chat                — Core streaming chat endpoint (SSE).
 *   - GET  /chat-history        — Paginated message history for a session.
 *   - DELETE /chat-history      — Delete all messages in a session.
 *   - GET  /sessions            — List the current user's chat sessions.
 *   - POST /sessions            — Create a new chat session.
 *   - POST /upload-image        — Upload a reference image via the WP media pipeline.
 *   - POST /figma-config        — Save a Figma Personal Access Token.
 *   - POST /figma-test          — Test the stored Figma connection.
 *   - POST /figma-frames        — Fetch frames from a Figma file URL.
 *   - POST /figma-context       — Get full Figma context (screenshots + design tokens).
 *
 * @package WPVibe
 */

defined( 'ABSPATH' ) || exit;

class VB_REST_API {

	private string $namespace = 'wpvibe/v1';
	private VB_Key_Storage     $key_storage;
	private VB_Key_Manager     $key_manager;
	private VB_Session_Manager $session_manager;
	private VB_AI_Router       $ai_router;
	private VB_Theme_Parser    $theme_parser;
	private VB_Theme_Writer    $theme_writer;
	private VB_Theme_Exporter  $theme_exporter;
	private VB_Preview_Engine  $preview_engine;

	/** @var VB_Figma_Client|null */
	private ?VB_Figma_Client $figma_client = null;

	/**
	 * @param VB_Key_Storage     $key_storage     Encrypted key storage.
	 * @param VB_Key_Manager     $key_manager     Key detection / validation.
	 * @param VB_Session_Manager $session_manager Chat session persistence.
	 * @param VB_AI_Router       $ai_router       AI provider routing layer.
	 * @param VB_Theme_Parser    $theme_parser    AI response parser.
	 * @param VB_Theme_Writer    $theme_writer    Safe theme file writer.
	 * @param VB_Theme_Exporter  $theme_exporter  Theme ZIP exporter.
	 * @param VB_Preview_Engine  $preview_engine  Theme preview generator.
	 */
	public function __construct(
		VB_Key_Storage $key_storage,
		VB_Key_Manager $key_manager,
		VB_Session_Manager $session_manager,
		VB_AI_Router $ai_router,
		VB_Theme_Parser $theme_parser,
		VB_Theme_Writer $theme_writer,
		VB_Theme_Exporter $theme_exporter,
		VB_Preview_Engine $preview_engine,
	) {
		$this->key_storage     = $key_storage;
		$this->key_manager     = $key_manager;
		$this->session_manager = $session_manager;
		$this->ai_router       = $ai_router;
		$this->theme_parser    = $theme_parser;
		$this->theme_writer    = $theme_writer;
		$this->theme_exporter  = $theme_exporter;
		$this->preview_engine  = $preview_engine;
	}

	/**
	 * Register all REST routes.
	 */
	public function register_routes(): void {

		// ------------------------------------------------------------------
		// Existing routes
		// ------------------------------------------------------------------

		// Validate and save an API key.
		register_rest_route(
			$this->namespace,
			'/validate-key',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_validate_key' ),
				'permission_callback' => array( $this, 'check_edit_themes' ),
				'args'                => array(
					'key' => array(
						'required'          => true,
						'type'              => 'string',
						
					),
				),
			)
		);

		// Get available models for the current key type.
		register_rest_route(
			$this->namespace,
			'/models',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_get_models' ),
				'permission_callback' => array( $this, 'check_edit_themes' ),
			)
		);

		// Save settings (model selection, onboarding complete).
		register_rest_route(
			$this->namespace,
			'/save-settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_save_settings' ),
				'permission_callback' => array( $this, 'check_edit_themes' ),
			)
		);

		// ------------------------------------------------------------------
		// New routes — chat, history, sessions
		// ------------------------------------------------------------------

		// Core streaming chat endpoint.
		register_rest_route(
			$this->namespace,
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_chat' ),
				'permission_callback' => array( $this, 'check_edit_themes' ),
				'args'                => array(
					'message'    => array(
						'required' => true,
						'type'     => 'string',
					),
					'session_id' => array(
						'required' => false,
						'type'     => 'integer',
					),
					'model'      => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);

		// Get paginated chat history for a session.
		register_rest_route(
			$this->namespace,
			'/chat-history',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_get_history' ),
				'permission_callback' => array( $this, 'check_edit_themes' ),
				'args'                => array(
					'session_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'before_id'  => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'limit'      => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 50,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Delete all messages in a session.
		register_rest_route(
			$this->namespace,
			'/chat-history',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'handle_delete_history' ),
				'permission_callback' => array( $this, 'check_edit_themes' ),
				'args'                => array(
					'session_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// List the current user's chat sessions.
		register_rest_route(
			$this->namespace,
			'/sessions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_get_sessions' ),
				'permission_callback' => array( $this, 'check_edit_themes' ),
			)
		);

		// Create a new chat session.
		register_rest_route(
			$this->namespace,
			'/sessions',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_create_session' ),
				'permission_callback' => array( $this, 'check_edit_themes' ),
				'args'                => array(
					'session_name' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'Untitled Theme',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'theme_slug' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// List installed WordPress themes.
		register_rest_route( $this->namespace, '/wp-themes', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_get_wp_themes' ),
			'permission_callback' => array( $this, 'check_edit_themes' ),
		) );

		// ------------------------------------------------------------------
		// Phase 3 routes — preview, theme versions, apply, export
		// ------------------------------------------------------------------

		// Theme preview (token-based auth, no login required).
		register_rest_route( $this->namespace, '/preview', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_preview' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'token' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// Theme versions list.
		register_rest_route( $this->namespace, '/theme-versions', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_get_theme_versions' ),
			'permission_callback' => array( $this, 'check_edit_themes' ),
			'args'                => array(
				'session_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		) );

		// Restore a specific theme version to disk (for preview navigation).
		register_rest_route( $this->namespace, '/restore-version', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_restore_version' ),
			'permission_callback' => array( $this, 'check_edit_themes' ),
			'args'                => array(
				'session_id' => array(
					'required' => true,
					'type'     => 'integer',
				),
				'version_index' => array(
					'required' => true,
					'type'     => 'integer',
				),
			),
		) );

		// Apply theme.
		register_rest_route( $this->namespace, '/apply-theme', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_apply_theme' ),
			'permission_callback' => array( $this, 'check_edit_themes' ),
		) );

		// Export theme as ZIP.
		register_rest_route( $this->namespace, '/export', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_export_theme' ),
			'permission_callback' => array( $this, 'check_edit_themes' ),
		) );

		// Security scan.
		register_rest_route( $this->namespace, '/security-scan', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_security_scan' ),
			'permission_callback' => array( $this, 'check_edit_themes' ),
		) );

		// Security fix.
		register_rest_route( $this->namespace, '/security-fix', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_security_fix' ),
			'permission_callback' => array( $this, 'check_edit_themes' ),
		) );

		// Image upload.
		register_rest_route( $this->namespace, '/upload-image', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_upload_image' ),
			'permission_callback' => array( $this, 'check_edit_themes' ),
		) );

		// ------------------------------------------------------------------
		// Phase 4 routes — Figma integration
		// ------------------------------------------------------------------

		// Figma integration.
		register_rest_route( $this->namespace, '/figma-config', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_save_figma_config' ),
			'permission_callback' => array( $this, 'check_edit_themes' ),
		) );

		register_rest_route( $this->namespace, '/figma-test', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_test_figma' ),
			'permission_callback' => array( $this, 'check_edit_themes' ),
		) );

		register_rest_route( $this->namespace, '/figma-frames', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_get_figma_frames' ),
			'permission_callback' => array( $this, 'check_edit_themes' ),
		) );

		register_rest_route( $this->namespace, '/figma-context', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_get_figma_context' ),
			'permission_callback' => array( $this, 'check_edit_themes' ),
		) );

		register_rest_route( $this->namespace, '/figma-disconnect', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_disconnect_figma' ),
			'permission_callback' => array( $this, 'check_edit_themes' ),
		) );

		// ------------------------------------------------------------------
		// Code Editor — get/save theme files
		// ------------------------------------------------------------------
		register_rest_route( $this->namespace, '/theme-files', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_get_theme_files' ),
			'permission_callback' => array( $this, 'check_edit_themes' ),
			'args'                => array(
				'session_id' => array( 'required' => true, 'type' => 'integer' ),
			),
		) );

		register_rest_route( $this->namespace, '/save-files', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_save_files' ),
			'permission_callback' => array( $this, 'check_edit_themes' ),
		) );

		// ------------------------------------------------------------------
		// Portal integration — announcements
		// ------------------------------------------------------------------
		register_rest_route( $this->namespace, '/announcements', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_get_announcements' ),
			'permission_callback' => array( $this, 'check_edit_themes' ),
		) );
	}

	// ======================================================================
	// Existing handlers — unchanged
	// ======================================================================

	/**
	 * POST /validate-key
	 * Detect key type, validate against provider, save on success.
	 */
	public function handle_validate_key( WP_REST_Request $request ): WP_REST_Response {
		$key  = $request->get_param( 'key' );
		$type = $this->key_manager->detect_key_type( $key );

		if ( 'unknown' === $type ) {
			return new WP_REST_Response(
				array(
					'valid'   => false,
					'message' => __( 'Unrecognized API key format. Please check your key and try again.', 'wpvibe' ),
					'models'  => array(),
				),
				200
			);
		}

		$result = $this->key_manager->validate_key( $key, $type );

		if ( $result['valid'] ) {
			// Save the encrypted key.
			$this->key_storage->save_key( $key, $type );

			VB_Logger::instance()->info( "API key validated and saved. Type: {$type}" );
		} else {
			VB_Logger::instance()->warning( "API key validation failed. Type: {$type}" );
		}

		return new WP_REST_Response(
			array(
				'valid'   => $result['valid'],
				'message' => $result['message'],
				'keyType' => $type,
				'models'  => $result['models'],
			),
			200
		);
	}

	/**
	 * GET /models
	 * Return available models for the currently configured key type.
	 */
	public function handle_get_models( WP_REST_Request $request ): WP_REST_Response {
		$key_type = $this->key_storage->get_key_type();

		if ( empty( $key_type ) ) {
			return new WP_REST_Response(
				array(
					'models'       => array(),
					'currentModel' => '',
					'message'      => __( 'No API key configured.', 'wpvibe' ),
				),
				200
			);
		}

		$models        = $this->key_manager->get_available_models( $key_type );
		$current_model = get_option( 'wpvibe_selected_model', '' );

		return new WP_REST_Response(
			array(
				'models'       => $models,
				'currentModel' => $current_model,
				'keyType'      => $key_type,
			),
			200
		);
	}

	/**
	 * POST /save-settings
	 * Save selected model and onboarding state.
	 */
	public function handle_save_settings( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();

		if ( isset( $body['selected_model'] ) ) {
			update_option( 'wpvibe_selected_model', sanitize_text_field( $body['selected_model'] ) );
		}

		if ( isset( $body['onboarding_complete'] ) && $body['onboarding_complete'] ) {
			update_option( 'wpvibe_onboarding_complete', true );
		}

		if ( isset( $body['css_framework'] ) ) {
			$allowed = array( 'tailwind', 'bootstrap', 'vanilla' );
			$value   = sanitize_text_field( $body['css_framework'] );
			if ( in_array( $value, $allowed, true ) ) {
				update_option( 'wpvibe_css_framework', $value, false );
			}
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	// ======================================================================
	// New handlers — chat, history, sessions
	// ======================================================================

	/**
	 * POST /chat — Core streaming chat endpoint.
	 *
	 * Accepts a user message, persists it, loads conversation context,
	 * and streams the AI response via Server-Sent Events (SSE).
	 *
	 * This method writes directly to the output buffer and calls exit()
	 * so WordPress does not wrap the response in a JSON envelope.
	 */
	public function handle_chat( WP_REST_Request $request ): void {
		$user_id = get_current_user_id();

		// 1. Rate-limit check.
		if ( ! $this->check_rate_limit( $user_id ) ) {
			$this->prepare_sse_headers();
			$this->send_sse_event(
				array(
					'type'    => 'error',
					'content' => __( 'Rate limit exceeded. You can send up to 30 messages per hour. Please wait and try again.', 'wpvibe' ),
				)
			);
			exit;
		}

		// 2. Extract parameters.
		$message         = $request->get_param( 'message' );
		$session_id      = $request->get_param( 'session_id' );
		$model           = $request->get_param( 'model' );
		$attachments_raw = $request->get_json_params()['attachments'] ?? array();

		if ( empty( $message ) ) {
			$this->prepare_sse_headers();
			$this->send_sse_event(
				array(
					'type'    => 'error',
					'content' => __( 'Message cannot be empty.', 'wpvibe' ),
				)
			);
			exit;
		}

		// Resolve the model: explicit param > saved setting > first available.
		if ( empty( $model ) ) {
			$model = get_option( 'wpvibe_selected_model', '' );
		}

		if ( empty( $model ) ) {
			$key_type        = $this->key_storage->get_key_type();
			$available       = $this->key_manager->get_available_models( $key_type );
			$first_available = reset( $available );
			$model           = is_array( $first_available ) ? ( $first_available['id'] ?? '' ) : '';
		}

		if ( empty( $model ) ) {
			$this->prepare_sse_headers();
			$this->send_sse_event(
				array(
					'type'    => 'error',
					'content' => __( 'No model selected. Please choose a model in settings.', 'wpvibe' ),
				)
			);
			exit;
		}

		// 3. Session: reuse existing or create a new one.
		if ( ! empty( $session_id ) ) {
			$session = $this->session_manager->get_session( (int) $session_id );

			if ( null === $session || (int) $session['user_id'] !== $user_id ) {
				$this->prepare_sse_headers();
				$this->send_sse_event(
					array(
						'type'    => 'error',
						'content' => __( 'Session not found.', 'wpvibe' ),
					)
				);
				exit;
			}

			$session_id = (int) $session['id'];
		} else {
			$session_id = $this->session_manager->create_session( $user_id, 'Untitled Theme', $model );
			$session    = $this->session_manager->get_session( $session_id );
		}

		// 4. Persist the user message.
		$user_message_id = $this->session_manager->add_message(
			$session_id,
			'user',
			wp_kses_post( $message ),
			! empty( $attachments_raw ) ? $attachments_raw : null
		);

		// 5. Load recent conversation context (last 20 messages).
		$history_rows = $this->session_manager->get_messages( $session_id, null, 20 );

		$key_type = $this->key_storage->get_key_type();
		$messages = $this->build_multimodal_messages( $history_rows, $key_type );

		// 5b. Inject current theme file context so the AI knows what exists.
		$theme_context = $this->build_theme_context( $session_id, $session );
		if ( '' !== $theme_context ) {
			// Insert as the second-to-last message (before the latest user message)
			// so the AI sees: [old history...] [theme context] [latest user prompt].
			$last_msg = array_pop( $messages );
			$messages[] = array(
				'role'    => 'user',
				'content' => $theme_context,
			);
			$messages[] = array(
				'role'    => 'assistant',
				'content' => 'Understood. I have the current theme context and will modify the existing files as needed.',
			);
			$messages[] = $last_msg;
		}

		// 6. Set SSE headers — must happen before any output.
		$this->prepare_sse_headers();

		// Send an initial event so the client knows the session context.
		$this->send_sse_event(
			array(
				'type'      => 'session_info',
				'sessionId' => $session_id,
			)
		);

		// 7. Stream the AI response.
		$response_buffer = '';
		$system_prompt   = $this->ai_router->get_system_prompt();

		$this->ai_router->stream_response(
			$messages,
			$model,
			$system_prompt,
			$response_buffer,
		);

		// --- Theme generation pipeline (Phase 3) ---
		$theme_data = $this->theme_parser->parse( $response_buffer );
		VB_Logger::instance()->info(
			'Theme parse: ' . ( null !== $theme_data
				? 'OK (' . count( $theme_data['files'] ) . ' files, preview=' . strlen( $theme_data['preview_html'] ) . 'b)'
				: 'NULL (buffer=' . strlen( $response_buffer ) . 'b)' )
		);

		if ( null !== $theme_data && ! empty( $theme_data['files'] ) ) {
			// Determine the theme slug.
			$theme_slug = $session['theme_slug']
				?? VB_Theme_Writer::generate_slug( $session_id, $session['session_name'] ?? '' );

			// If this is the first version, save the slug to the session.
			if ( empty( $session['theme_slug'] ) ) {
				global $wpdb;
				$wpdb->update(
					$wpdb->prefix . 'wpvibe_sessions',
					array( 'theme_slug' => $theme_slug ),
					array( 'id' => $session_id ),
					array( '%s' ),
					array( '%d' )
				);
			}

			// Merge with previous version's files (AI only returns changed files).
			$previous_versions = $this->session_manager->get_theme_versions( $session_id );
			$accumulated_files = array();

			if ( ! empty( $previous_versions ) ) {
				$last_version = end( $previous_versions );
				$prev_files   = is_string( $last_version['files_snapshot'] )
					? json_decode( $last_version['files_snapshot'], true )
					: ( $last_version['files_snapshot'] ?? array() );

				if ( is_array( $prev_files ) ) {
					foreach ( $prev_files as $f ) {
						if ( isset( $f['path'] ) ) {
							$accumulated_files[ $f['path'] ] = $f;
						}
					}
				}
			}

			// Overlay new files on top of accumulated files.
			foreach ( $theme_data['files'] as $f ) {
				if ( isset( $f['path'] ) ) {
					$accumulated_files[ $f['path'] ] = $f;
				}
			}

			$merged_files = array_values( $accumulated_files );

			// Write theme files to disk.
			$write_result = $this->theme_writer->write_theme( $theme_slug, $merged_files );

			if ( $write_result['success'] ) {
				$version_number = count( $previous_versions ) + 1;

				// Save the theme version snapshot.
				$version_id = $this->session_manager->save_theme_version(
					$session_id,
					$version_number,
					$theme_slug,
					$merged_files,
					null // message_id not yet available
				);

				// Create a preview token.
				$preview_html  = $theme_data['preview_html'] ?? '';
				$preview_token = $this->preview_engine->create_preview_token(
					$version_id,
					$theme_slug,
					$preview_html
				);
				$preview_url = $this->preview_engine->get_preview_url( $preview_token );

				// Extract changed file paths for the SSE event.
				$files_changed = array_map(
					static fn( array $f ): string => $f['path'] ?? '',
					$theme_data['files']
				);

				VB_Logger::instance()->info( 'Preview URL: ' . $preview_url );

				// Send theme_update SSE event.
				$theme_update_event = array(
					'type'        => 'theme_update',
					'themeUpdate' => array(
						'versionId'     => $version_id,
						'previewUrl'    => $preview_url,
						'filesChanged'  => $files_changed,
						'themeSlug'     => $theme_slug,
						'versionNumber' => $version_number,
						'totalVersions' => $version_number,
					),
				);
				echo 'data: ' . wp_json_encode( $theme_update_event ) . "\n\n";
				flush();
			} else {
				VB_Logger::instance()->error(
					'Theme write failed: ' . wp_json_encode( $write_result['errors'] ?? array() )
				);
			}
		}
		// --- End theme generation pipeline ---

		// 8. Persist the assistant response.
		$assistant_message_id = 0;
		if ( ! empty( $response_buffer ) ) {
			$assistant_message_id = $this->session_manager->add_message(
				$session_id,
				'assistant',
				$response_buffer
			);
		}

		// 9. Send the final "done" event.
		$this->send_sse_event(
			array(
				'type'      => 'done',
				'sessionId' => $session_id,
				'messageId' => $assistant_message_id,
			)
		);

		// 10. Flush and terminate — prevent WP from adding JSON wrappers.
		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
		exit;
	}

	/**
	 * GET /chat-history
	 * Return paginated messages for a session.
	 */
	public function handle_get_history( WP_REST_Request $request ): WP_REST_Response {
		$session_id = (int) $request->get_param( 'session_id' );
		$before_id  = $request->get_param( 'before_id' );
		$limit      = (int) $request->get_param( 'limit' );
		$user_id    = get_current_user_id();

		if ( $limit < 1 || $limit > 200 ) {
			$limit = 50;
		}

		// Verify the session belongs to this user.
		$session = $this->session_manager->get_session( $session_id );

		if ( null === $session || (int) $session['user_id'] !== $user_id ) {
			return new WP_REST_Response(
				array(
					'messages' => array(),
					'session'  => null,
					'message'  => __( 'Session not found.', 'wpvibe' ),
				),
				404
			);
		}

		$messages = $this->session_manager->get_messages(
			$session_id,
			! empty( $before_id ) ? (int) $before_id : null,
			$limit
		);

		// Format for the front-end.
		$formatted = array_map(
			static function ( array $row ): array {
				return array(
					'id'          => (int) $row['id'],
					'role'        => $row['role'],
					'content'     => $row['content'],
					'attachments' => $row['attachments'],
					'tokenCount'  => (int) $row['token_count'],
					'createdAt'   => $row['created_at'],
				);
			},
			$messages
		);

		return new WP_REST_Response(
			array(
				'messages' => $formatted,
				'session'  => array(
					'id'          => (int) $session['id'],
					'sessionName' => $session['session_name'],
					'themeSlug'   => $session['theme_slug'],
					'modelUsed'   => $session['model_used'],
					'createdAt'   => $session['created_at'],
					'updatedAt'   => $session['updated_at'],
				),
			),
			200
		);
	}

	/**
	 * DELETE /chat-history
	 * Remove all messages from a session.
	 */
	public function handle_delete_history( WP_REST_Request $request ): WP_REST_Response {
		$session_id = (int) $request->get_param( 'session_id' );
		$user_id    = get_current_user_id();

		// Verify session ownership.
		$session = $this->session_manager->get_session( $session_id );

		if ( null === $session || (int) $session['user_id'] !== $user_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Session not found.', 'wpvibe' ),
				),
				404
			);
		}

		$success = $this->session_manager->delete_messages( $session_id );

		return new WP_REST_Response(
			array( 'success' => $success ),
			200
		);
	}

	/**
	 * GET /sessions
	 * Return all chat sessions for the current user, newest first.
	 */
	public function handle_get_sessions( WP_REST_Request $request ): WP_REST_Response {
		$user_id  = get_current_user_id();
		$sessions = $this->session_manager->list_sessions( $user_id );

		$formatted = array_map(
			static function ( array $row ): array {
				return array(
					'id'          => (int) $row['id'],
					'sessionName' => $row['session_name'],
					'themeSlug'   => $row['theme_slug'],
					'modelUsed'   => $row['model_used'],
					'createdAt'   => $row['created_at'],
					'updatedAt'   => $row['updated_at'],
				);
			},
			$sessions
		);

		return new WP_REST_Response(
			array( 'sessions' => $formatted ),
			200
		);
	}

	/**
	 * GET /wp-themes
	 * List all installed WordPress themes.
	 */
	public function handle_get_wp_themes( WP_REST_Request $request ): WP_REST_Response {
		$wp_themes    = wp_get_themes();
		$active_slug  = get_stylesheet();
		$themes       = array();

		foreach ( $wp_themes as $slug => $theme ) {
			$screenshot = $theme->get_screenshot();
			$themes[]   = array(
				'slug'       => $slug,
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
				'author'     => $theme->get( 'Author' ),
				'description' => $theme->get( 'Description' ),
				'screenshot' => $screenshot ? $screenshot : '',
				'isActive'   => ( $slug === $active_slug ),
			);
		}

		// Sort: active theme first, then alphabetical.
		usort( $themes, static function ( $a, $b ) {
			if ( $a['isActive'] !== $b['isActive'] ) {
				return $a['isActive'] ? -1 : 1;
			}
			return strcasecmp( $a['name'], $b['name'] );
		} );

		return new WP_REST_Response( array( 'themes' => $themes ) );
	}

	/**
	 * POST /sessions
	 * Create a new chat session. Optionally import an existing theme.
	 */
	public function handle_create_session( WP_REST_Request $request ): WP_REST_Response {
		$user_id      = get_current_user_id();
		$session_name = $request->get_param( 'session_name' );
		$theme_slug   = $request->get_param( 'theme_slug' );
		$model        = get_option( 'wpvibe_selected_model', '' );

		$session_id = $this->session_manager->create_session(
			$user_id,
			$session_name,
			$model
		);

		if ( 0 === $session_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Failed to create session.', 'wpvibe' ),
				),
				500
			);
		}

		// If an existing theme slug was provided, import its files.
		if ( '' !== $theme_slug ) {
			$import_result = $this->import_existing_theme( $session_id, $theme_slug );

			if ( is_wp_error( $import_result ) ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => $import_result->get_error_message(),
					),
					400
				);
			}

			// Save the theme slug to the session.
			$this->session_manager->update_session( $session_id, array(
				'theme_slug' => $theme_slug,
			) );
		}

		$session = $this->session_manager->get_session( $session_id );

		return new WP_REST_Response(
			array(
				'id'          => $session_id,
				'sessionName' => $session_name,
				'themeSlug'   => $session['theme_slug'] ?? '',
				'modelUsed'   => $session['model_used'] ?? $model,
				'createdAt'   => $session['created_at'] ?? current_time( 'mysql', true ),
				'updatedAt'   => $session['updated_at'] ?? current_time( 'mysql', true ),
			),
			201
		);
	}

	/**
	 * Import an existing WordPress theme's files into a session as version 1.
	 *
	 * Reads all theme files from disk and saves them as a theme version snapshot
	 * so the AI has full context of the existing theme.
	 *
	 * @param int    $session_id Session ID.
	 * @param string $theme_slug Existing theme directory name.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	private function import_existing_theme( int $session_id, string $theme_slug ) {
		$theme_root = get_theme_root();
		$theme_dir  = trailingslashit( $theme_root ) . $theme_slug;

		if ( ! is_dir( $theme_dir ) ) {
			return new \WP_Error(
				'theme_not_found',
				__( 'Theme directory not found.', 'wpvibe' )
			);
		}

		$files          = array();
		$allowed_ext    = array( 'php', 'css', 'js', 'json', 'html', 'txt', 'svg' );
		$max_file_size  = 512 * 1024; // 512 KB per file.
		$max_total_size = 5 * 1024 * 1024; // 5 MB total.
		$total_size     = 0;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $theme_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$ext = strtolower( $file->getExtension() );
			if ( ! in_array( $ext, $allowed_ext, true ) ) {
				continue;
			}

			// Skip node_modules, vendor dirs, hidden files.
			$relative_path = substr( $file->getPathname(), strlen( $theme_dir ) + 1 );
			if ( preg_match( '#(^|/)(node_modules|vendor|\.git|\.svn)/#', $relative_path ) ) {
				continue;
			}

			$size = $file->getSize();
			if ( $size > $max_file_size ) {
				continue; // Skip overly large files.
			}

			if ( $total_size + $size > $max_total_size ) {
				break; // Stop if total snapshot would be too large.
			}

			$content = file_get_contents( $file->getPathname() );
			if ( false === $content ) {
				continue;
			}

			$total_size += $size;
			$files[]     = array(
				'path'    => $relative_path,
				'content' => $content,
			);
		}

		if ( empty( $files ) ) {
			return new \WP_Error(
				'no_files',
				__( 'No importable files found in the theme.', 'wpvibe' )
			);
		}

		// Save as version 1.
		$this->session_manager->save_theme_version(
			$session_id,
			1,
			$theme_slug,
			$files
		);

		// Create a preview token so the preview panel works immediately.
		$preview_html  = '';
		$version_id    = 1;
		$preview_token = $this->preview_engine->create_preview_token( $version_id, $theme_slug, $preview_html );

		VB_Logger::instance()->info(
			"Imported existing theme '{$theme_slug}': " . count( $files ) . " files, {$total_size} bytes"
		);

		return true;
	}

	// ======================================================================
	// Phase 3 handlers — preview, theme versions, apply, export
	// ======================================================================

	/**
	 * Serve a theme preview via token-based auth.
	 */
	public function handle_preview( WP_REST_Request $request ) {
		// Rate-limit preview requests by IP: max 60 per minute.
		$ip_hash    = md5( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
		$rate_key   = 'vb_preview_rate_' . $ip_hash;
		$rate_count = (int) get_transient( $rate_key );

		if ( $rate_count >= 60 ) {
			return new WP_REST_Response( 'Too many preview requests. Please wait.', 429 );
		}

		set_transient( $rate_key, $rate_count + 1, 60 );

		$token = $request->get_param( 'token' );
		$data  = $this->preview_engine->validate_token( $token );

		if ( null === $data ) {
			return new WP_REST_Response( 'Preview expired or invalid.', 404 );
		}

		header( 'Content-Type: text/html; charset=UTF-8' );
		header( "Content-Security-Policy: default-src * 'unsafe-inline' 'unsafe-eval' data: blob:; frame-ancestors 'self';" );
		header( 'X-Content-Type-Options: nosniff' );

		echo $data['preview_html'];
		exit;
	}

	/**
	 * Return theme versions for a session (without file content).
	 */
	public function handle_get_theme_versions( WP_REST_Request $request ): WP_REST_Response {
		$session_id = (int) $request->get_param( 'session_id' );
		$user_id    = get_current_user_id();

		// Verify session ownership.
		$session = $this->session_manager->get_session( $session_id );
		if ( null === $session || (int) $session['user_id'] !== $user_id ) {
			return new WP_REST_Response( array( 'error' => 'Session not found.' ), 404 );
		}

		$versions = $this->session_manager->get_theme_versions( $session_id );

		// Generate preview URLs for each version from the files snapshot.
		$preview_engine = $this->preview_engine;
		$theme_parser   = $this->theme_parser;

		$versions = array_map( static function ( array $v ) use ( $preview_engine, $theme_parser ): array {
			$files = $v['files_snapshot'] ?? array();

			// Generate a preview from the theme files.
			$preview_html = $theme_parser->generate_preview_from_files( $files );

			if ( '' !== $preview_html ) {
				$token = $preview_engine->create_preview_token( (int) $v['id'], $v['theme_slug'] ?? '', $preview_html );
				$v['previewUrl'] = $preview_engine->get_preview_url( $token );
			} else {
				$v['previewUrl'] = '';
			}

			unset( $v['files_snapshot'] );
			return $v;
		}, $versions );

		return new WP_REST_Response( array( 'versions' => $versions ), 200 );
	}

	/**
	 * Apply a theme version to the WordPress site.
	 */
	/**
	 * POST /restore-version
	 * Write a specific version's files to disk and return a fresh preview URL.
	 * This does NOT switch the active theme — it only updates the theme directory
	 * so the preview iframe shows the correct version.
	 */
	public function handle_restore_version( WP_REST_Request $request ): WP_REST_Response {
		$session_id    = (int) $request->get_param( 'session_id' );
		$version_index = (int) $request->get_param( 'version_index' );
		$user_id       = get_current_user_id();

		$session = $this->session_manager->get_session( $session_id );
		if ( null === $session || (int) $session['user_id'] !== $user_id ) {
			return new WP_REST_Response( array( 'error' => 'Session not found.' ), 404 );
		}

		$versions = $this->session_manager->get_theme_versions( $session_id );
		if ( $version_index < 0 || $version_index >= count( $versions ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid version index.' ), 400 );
		}

		$version = $versions[ $version_index ];
		$files   = is_string( $version['files_snapshot'] )
			? json_decode( $version['files_snapshot'], true )
			: ( $version['files_snapshot'] ?? array() );

		if ( empty( $files ) || empty( $version['theme_slug'] ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid theme version data.' ), 400 );
		}

		$theme_slug = $version['theme_slug'];

		// Write the version's files to the theme directory.
		$result = $this->theme_writer->write_theme( $theme_slug, $files );
		if ( ! $result['success'] ) {
			return new WP_REST_Response( array( 'error' => 'Failed to write theme files.' ), 500 );
		}

		// Generate a fresh preview URL.
		$preview_html  = $this->theme_parser->generate_preview_from_files( $files );
		$preview_token = $this->preview_engine->create_preview_token(
			(int) $version['id'],
			$theme_slug,
			$preview_html
		);
		$preview_url = $this->preview_engine->get_preview_url( $preview_token );

		return new WP_REST_Response( array(
			'success'    => true,
			'previewUrl' => $preview_url,
		) );
	}

	public function handle_apply_theme( WP_REST_Request $request ): WP_REST_Response {
		$session_id = (int) $request->get_param( 'session_id' );
		$version_id = $request->get_param( 'version_id' );
		$user_id    = get_current_user_id();

		// Verify session ownership.
		$session = $this->session_manager->get_session( $session_id );
		if ( null === $session || (int) $session['user_id'] !== $user_id ) {
			return new WP_REST_Response( array( 'error' => 'Session not found.' ), 404 );
		}

		// Get the version to apply.
		if ( null !== $version_id ) {
			$version = $this->session_manager->get_theme_version( (int) $version_id, $user_id );
		} else {
			// Get latest version.
			$versions = $this->session_manager->get_theme_versions( $session_id );
			$version  = ! empty( $versions ) ? end( $versions ) : null;
		}

		if ( null === $version || empty( $version['files_snapshot'] ) ) {
			return new WP_REST_Response( array( 'error' => 'No theme version found.' ), 404 );
		}

		$files      = is_string( $version['files_snapshot'] )
			? json_decode( $version['files_snapshot'], true )
			: $version['files_snapshot'];
		$theme_slug = $version['theme_slug'];

		if ( empty( $files ) || empty( $theme_slug ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid theme version data.' ), 400 );
		}

		// Write theme files.
		$result = $this->theme_writer->write_theme( $theme_slug, $files );

		if ( ! $result['success'] ) {
			return new WP_REST_Response( array(
				'error'  => 'Failed to write theme files.',
				'errors' => $result['errors'] ?? array(),
			), 500 );
		}

		// Switch to the generated theme.
		switch_theme( $theme_slug );

		// Mark version as applied.
		$this->session_manager->restore_theme_version( (int) $version['id'] );

		return new WP_REST_Response( array(
			'success'   => true,
			'themeSlug' => $theme_slug,
		), 200 );
	}

	/**
	 * Export a theme version as a ZIP download.
	 */
	public function handle_export_theme( WP_REST_Request $request ): WP_REST_Response {
		$session_id = (int) $request->get_param( 'session_id' );
		$version_id = $request->get_param( 'version_id' );
		$user_id    = get_current_user_id();

		// Verify session ownership.
		$session = $this->session_manager->get_session( $session_id );
		if ( null === $session || (int) $session['user_id'] !== $user_id ) {
			return new WP_REST_Response( array( 'error' => 'Session not found.' ), 404 );
		}

		// Get the version to export.
		if ( null !== $version_id ) {
			$version = $this->session_manager->get_theme_version( (int) $version_id, $user_id );
		} else {
			$versions = $this->session_manager->get_theme_versions( $session_id );
			$version  = ! empty( $versions ) ? end( $versions ) : null;
		}

		if ( null === $version || empty( $version['files_snapshot'] ) ) {
			return new WP_REST_Response( array( 'error' => 'No theme version found.' ), 404 );
		}

		$files      = is_string( $version['files_snapshot'] )
			? json_decode( $version['files_snapshot'], true )
			: $version['files_snapshot'];
		$theme_slug = $version['theme_slug'];

		if ( empty( $files ) || empty( $theme_slug ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid theme version data.' ), 400 );
		}

		$result = $this->theme_exporter->export( $files, $theme_slug );

		if ( ! $result['success'] ) {
			return new WP_REST_Response( array(
				'error' => $result['error'] ?? 'Export failed.',
			), 500 );
		}

		return new WP_REST_Response( array(
			'success' => true,
			'url'     => $result['url'],
		), 200 );
	}

	// ======================================================================
	// Security scan / fix handlers
	// ======================================================================

	/**
	 * Scan theme files for security vulnerabilities using the AI.
	 */
	public function handle_security_scan( WP_REST_Request $request ): WP_REST_Response {
		$session_id = (int) $request->get_param( 'session_id' );
		$version_id = $request->get_param( 'version_id' );
		$user_id    = get_current_user_id();

		$session = $this->session_manager->get_session( $session_id );
		if ( null === $session || (int) $session['user_id'] !== $user_id ) {
			return new WP_REST_Response( array( 'error' => 'Session not found.' ), 404 );
		}

		// Get the version to scan.
		if ( null !== $version_id ) {
			$version = $this->session_manager->get_theme_version( (int) $version_id, $user_id );
		} else {
			$versions = $this->session_manager->get_theme_versions( $session_id );
			$version  = ! empty( $versions ) ? end( $versions ) : null;
		}

		if ( null === $version || empty( $version['files_snapshot'] ) ) {
			return new WP_REST_Response( array( 'error' => 'No theme version found.' ), 404 );
		}

		$files = is_string( $version['files_snapshot'] )
			? json_decode( $version['files_snapshot'], true )
			: $version['files_snapshot'];

		if ( empty( $files ) ) {
			return new WP_REST_Response( array( 'error' => 'No files to scan.' ), 400 );
		}

		// Build the file content for scanning (only code files).
		$scannable_extensions = array( 'php', 'js', 'css', 'html' );
		$file_text            = '';
		foreach ( $files as $file ) {
			$ext = strtolower( pathinfo( $file['path'] ?? '', PATHINFO_EXTENSION ) );
			if ( in_array( $ext, $scannable_extensions, true ) ) {
				$file_text .= "=== FILE: {$file['path']} ===\n{$file['content']}\n\n";
			}
		}

		if ( '' === $file_text ) {
			return new WP_REST_Response( array(
				'safe'       => true,
				'findings'   => array(),
				'summary'    => 'No scannable code files found.',
				'version_id' => (int) $version['id'],
				'session_id' => $session_id,
			), 200 );
		}

		$model          = get_option( 'wpvibe_selected_model', 'claude-sonnet-4-5-20250514' );
		$system_prompt  = $this->get_security_scan_prompt();
		$scan_messages  = array(
			array(
				'role'    => 'user',
				'content' => "Analyze these WordPress theme files for security vulnerabilities:\n\n" . $file_text,
			),
		);

		try {
			$response_text = $this->ai_router->complete_response( $scan_messages, $model, $system_prompt );
		} catch ( \Throwable $e ) {
			VB_Logger::instance()->error( 'Security scan AI call failed: ' . $e->getMessage() );
			// On failure, treat as safe so the user isn't blocked.
			return new WP_REST_Response( array(
				'safe'       => true,
				'findings'   => array(),
				'summary'    => 'Security scan could not complete. Proceeding as safe.',
				'version_id' => (int) $version['id'],
				'session_id' => $session_id,
			), 200 );
		}

		// Parse AI JSON response.
		$scan_result = $this->parse_security_scan_response( $response_text );

		$scan_result['version_id'] = (int) $version['id'];
		$scan_result['session_id'] = $session_id;

		return new WP_REST_Response( $scan_result, 200 );
	}

	/**
	 * Fix security issues in theme files using the AI.
	 */
	public function handle_security_fix( WP_REST_Request $request ): WP_REST_Response {
		$session_id = (int) $request->get_param( 'session_id' );
		$version_id = (int) $request->get_param( 'version_id' );
		$findings   = $request->get_json_params()['findings'] ?? array();
		$user_id    = get_current_user_id();

		$session = $this->session_manager->get_session( $session_id );
		if ( null === $session || (int) $session['user_id'] !== $user_id ) {
			return new WP_REST_Response( array( 'error' => 'Session not found.' ), 404 );
		}

		$version = $this->session_manager->get_theme_version( $version_id, $user_id );
		if ( null === $version || empty( $version['files_snapshot'] ) ) {
			return new WP_REST_Response( array( 'error' => 'Theme version not found.' ), 404 );
		}

		$files = is_string( $version['files_snapshot'] )
			? json_decode( $version['files_snapshot'], true )
			: $version['files_snapshot'];
		$theme_slug = $version['theme_slug'];

		if ( empty( $files ) || empty( $theme_slug ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid theme version data.' ), 400 );
		}

		// Build file content + findings for the fix prompt.
		$file_text = '';
		foreach ( $files as $file ) {
			$file_text .= "=== FILE: {$file['path']} ===\n{$file['content']}\n\n";
		}

		$findings_text = wp_json_encode( $findings, JSON_PRETTY_PRINT );

		$model         = get_option( 'wpvibe_selected_model', 'claude-sonnet-4-5-20250514' );
		$system_prompt = $this->get_security_fix_prompt();
		$fix_messages  = array(
			array(
				'role'    => 'user',
				'content' => "Fix the security vulnerabilities listed below. Return ONLY the modified files with their COMPLETE content.\n\n"
					. "SECURITY FINDINGS:\n{$findings_text}\n\n"
					. "THEME FILES:\n{$file_text}",
			),
		);

		try {
			$response_text = $this->ai_router->complete_response( $fix_messages, $model, $system_prompt );
		} catch ( \Throwable $e ) {
			VB_Logger::instance()->error( 'Security fix AI call failed: ' . $e->getMessage() );
			return new WP_REST_Response( array(
				'error' => 'Security fix failed. Please try again.',
			), 500 );
		}

		// Parse the fix response — same JSON format as theme generation.
		$fix_data = $this->theme_parser->parse( $response_text );

		if ( empty( $fix_data['files'] ) ) {
			return new WP_REST_Response( array(
				'error' => 'AI did not return fixed files.',
			), 500 );
		}

		// Merge fixed files with original files.
		$files_by_path = array();
		foreach ( $files as $file ) {
			$files_by_path[ $file['path'] ] = $file;
		}
		foreach ( $fix_data['files'] as $fixed_file ) {
			$files_by_path[ $fixed_file['path'] ] = $fixed_file;
		}
		$merged_files = array_values( $files_by_path );

		// Determine next version number.
		$existing_versions = $this->session_manager->get_theme_versions( $session_id );
		$version_number    = count( $existing_versions ) + 1;

		// Save as a new theme version.
		$new_version_id = $this->session_manager->save_theme_version(
			$session_id,
			$version_number,
			$theme_slug,
			$merged_files,
		);

		// Write to disk.
		$this->theme_writer->write_theme( $theme_slug, $merged_files );

		// Generate preview URL.
		$preview_html  = $this->theme_parser->generate_preview_from_files( $merged_files );
		$preview_token = $this->preview_engine->create_preview_token(
			$new_version_id,
			$theme_slug,
			$preview_html
		);
		$preview_url = $this->preview_engine->get_preview_url( $preview_token );

		return new WP_REST_Response( array(
			'success'       => true,
			'previewUrl'    => $preview_url,
			'versionId'     => $new_version_id,
			'versionNumber' => $version_number,
			'themeSlug'     => $theme_slug,
		), 200 );
	}

	/**
	 * Get the system prompt for security scanning.
	 */
	private function get_security_scan_prompt(): string {
		return <<<'PROMPT'
You are a WordPress security expert. Analyze the provided WordPress theme files for security vulnerabilities.

Check for these categories:
1. CRITICAL: Remote Code Execution — dangerous functions used with dynamic input (e.g. passthru, shell_exec, system, popen, proc_open, assert with variables, preg_replace with /e modifier, create_function)
2. CRITICAL: SQL Injection — direct variable interpolation in SQL queries, missing $wpdb->prepare()
3. HIGH: Cross-Site Scripting (XSS) — unescaped output, missing esc_html/esc_attr/esc_url/wp_kses
4. HIGH: CSRF — form submissions without wp_nonce_field/wp_verify_nonce
5. HIGH: File Inclusion — include/require with user-controlled input, file_get_contents with variables
6. MEDIUM: Unsafe WordPress patterns — direct $_GET/$_POST/$_REQUEST usage without sanitization, direct database queries without prepare
7. MEDIUM: Information Disclosure — phpinfo(), error_reporting(E_ALL), debug output left in production code
8. LOW: base64_decode of dynamic input, unvalidated redirects

RESPONSE FORMAT — PURE JSON only (no markdown, no text outside JSON):
{
  "safe": true|false,
  "findings": [
    {
      "severity": "critical"|"high"|"medium"|"low",
      "category": "Category name",
      "file": "filename.php",
      "line": 42,
      "description": "Brief description of the vulnerability",
      "code_snippet": "the problematic code line"
    }
  ],
  "summary": "One-sentence overall assessment"
}

If no issues are found, return: {"safe": true, "findings": [], "summary": "No security issues detected."}
PROMPT;
	}

	/**
	 * Get the system prompt for security fixing.
	 */
	private function get_security_fix_prompt(): string {
		return <<<'PROMPT'
You are a WordPress security expert. Fix the security vulnerabilities listed in the user message.

CRITICAL RULES:
1. ONLY fix security issues. Do NOT change design, layout, colors, fonts, content, or functionality.
2. Return ONLY files you modified, with their COMPLETE content (not diffs).
3. Use proper WordPress security functions:
   - esc_html(), esc_attr(), esc_url() for output escaping
   - wp_kses() for allowing specific HTML
   - sanitize_text_field(), absint() for input sanitization
   - $wpdb->prepare() for database queries
   - wp_nonce_field() / wp_verify_nonce() for CSRF protection
   - wp_enqueue_script/style instead of direct script/style output
4. Remove any dangerous functions (passthru, shell_exec, system, etc.) and replace with safe alternatives.
5. Preserve ALL visual appearance, content, and functionality.

RESPONSE FORMAT — PURE JSON only (no markdown, no text outside JSON):
{
  "message": "Brief description of fixes applied",
  "changes_summary": ["Fix 1", "Fix 2"],
  "files": [
    {"path": "functions.php", "content": "...complete fixed file content..."}
  ],
  "preview_html": ""
}
PROMPT;
	}

	/**
	 * Parse the AI's security scan response into a structured result.
	 *
	 * @param string $response_text Raw AI response text.
	 * @return array Parsed scan result with safe, findings, summary keys.
	 */
	private function parse_security_scan_response( string $response_text ): array {
		$default = array(
			'safe'     => true,
			'findings' => array(),
			'summary'  => 'Could not parse scan results. Proceeding as safe.',
		);

		// Try to extract JSON from the response (may be wrapped in markdown fences).
		$json_str = $response_text;
		if ( preg_match( '/\{[\s\S]*\}/', $response_text, $matches ) ) {
			$json_str = $matches[0];
		}

		$data = json_decode( $json_str, true );
		if ( ! is_array( $data ) ) {
			VB_Logger::instance()->warning( 'Security scan returned non-JSON response.' );
			return $default;
		}

		return array(
			'safe'     => (bool) ( $data['safe'] ?? true ),
			'findings' => $data['findings'] ?? array(),
			'summary'  => $data['summary'] ?? 'Scan complete.',
		);
	}

	// ======================================================================
	// Phase 4 handlers — image upload
	// ======================================================================

	/**
	 * Handle image upload via the WordPress media pipeline.
	 *
	 * Validates the file (type, size), uploads via wp_handle_upload(),
	 * registers it in the media library, and returns metadata the
	 * frontend can use to attach the image to a chat message.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_upload_image( WP_REST_Request $request ): WP_REST_Response {
		try {
			$files = $request->get_file_params();

			if ( empty( $files['file'] ) ) {
				return new WP_REST_Response( array( 'message' => 'No file provided.' ), 400 );
			}

			$file = $files['file'];

			// Check for PHP upload errors.
			if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
				$php_errors = array(
					UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload_max_filesize.',
					UPLOAD_ERR_FORM_SIZE  => 'File exceeds form MAX_FILE_SIZE.',
					UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
					UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
					UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder.',
					UPLOAD_ERR_CANT_WRITE => 'Server failed to write file to disk.',
					UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
				);
				$err_code = (int) $file['error'];
				$err_msg  = $php_errors[ $err_code ] ?? "PHP upload error code: {$err_code}";
				return new WP_REST_Response( array( 'message' => $err_msg ), 400 );
			}

			// Validate temp file exists.
			if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
				return new WP_REST_Response( array( 'message' => 'Upload validation failed — no temp file.' ), 400 );
			}

			// Validate MIME type using magic bytes.
			$allowed_types = array( 'image/png', 'image/jpeg', 'image/webp', 'image/gif' );
			$finfo         = finfo_open( FILEINFO_MIME_TYPE );
			$mime_type     = finfo_file( $finfo, $file['tmp_name'] );
			finfo_close( $finfo );

			if ( ! in_array( $mime_type, $allowed_types, true ) ) {
				return new WP_REST_Response(
					array( 'message' => 'Invalid file type. Allowed: PNG, JPEG, WebP, GIF.' ),
					400
				);
			}

			// Validate file size (max 10 MB).
			$max_size = 10 * 1024 * 1024;
			if ( $file['size'] > $max_size ) {
				return new WP_REST_Response(
					array( 'message' => 'File too large. Maximum size is 10 MB.' ),
					400
				);
			}

			// Require the WordPress file handling functions.
			if ( ! function_exists( 'wp_handle_upload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( ! function_exists( 'media_handle_upload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
			}
			if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			$upload = wp_handle_upload( $file, array( 'test_form' => false ) );

			if ( isset( $upload['error'] ) ) {
				return new WP_REST_Response(
					array( 'message' => 'Upload error: ' . $upload['error'] ),
					500
				);
			}

			if ( empty( $upload['file'] ) || empty( $upload['url'] ) ) {
				return new WP_REST_Response(
					array( 'message' => 'Upload succeeded but returned incomplete data.' ),
					500
				);
			}

			// Register in the WordPress media library.
			$attachment_data = array(
				'post_mime_type' => $upload['type'],
				'post_title'     => sanitize_file_name( pathinfo( $upload['file'], PATHINFO_FILENAME ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			);

			$attachment_id = wp_insert_attachment( $attachment_data, $upload['file'] );

			if ( is_wp_error( $attachment_id ) ) {
				return new WP_REST_Response(
					array( 'message' => 'Media library error: ' . $attachment_id->get_error_message() ),
					500
				);
			}

			// Generate thumbnails and other image sizes.
			$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
			wp_update_attachment_metadata( $attachment_id, $metadata );

			$thumbnail_url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );

			return new WP_REST_Response( array(
				'id'           => (string) $attachment_id,
				'url'          => $upload['url'],
				'thumbnailUrl' => $thumbnail_url ?: $upload['url'],
				'mediaType'    => $upload['type'],
			), 201 );

		} catch ( \Throwable $e ) {
			VB_Logger::instance()->error( 'Image upload exception: ' . $e->getMessage() );
			return new WP_REST_Response(
				array( 'message' => 'An unexpected error occurred during upload. Please try again.' ),
				500
			);
		}
	}

	// ======================================================================
	// Phase 4 handlers — Figma integration
	// ======================================================================

	/**
	 * Save a Figma Personal Access Token.
	 */
	public function handle_save_figma_config( WP_REST_Request $request ): WP_REST_Response {
		$token = $request->get_param( 'token' );

		if ( empty( $token ) ) {
			return new WP_REST_Response( array( 'message' => 'Token is required.' ), 400 );
		}

		// Validate the token by checking the current user endpoint.
		$figma  = $this->get_figma_client();
		$user   = $figma->get_current_user( $token );

		if ( null === $user ) {
			return new WP_REST_Response(
				array( 'message' => 'Invalid Figma token. Could not authenticate.' ),
				400
			);
		}

		$this->key_storage->save_figma_token( $token );

		return new WP_REST_Response( array(
			'success'  => true,
			'userName' => $user['handle'] ?? '',
		), 200 );
	}

	/**
	 * Test the Figma connection with the stored token.
	 */
	public function handle_test_figma( WP_REST_Request $request ): WP_REST_Response {
		$token = $this->key_storage->get_figma_token();

		if ( '' === $token ) {
			return new WP_REST_Response( array(
				'connected' => false,
				'userName'  => '',
			), 200 );
		}

		$figma = $this->get_figma_client();
		$user  = $figma->get_current_user( $token );

		return new WP_REST_Response( array(
			'connected' => null !== $user,
			'userName'  => $user['handle'] ?? '',
		), 200 );
	}

	/**
	 * Disconnect Figma by removing the stored token.
	 */
	public function handle_disconnect_figma( WP_REST_Request $request ): WP_REST_Response {
		delete_option( 'wpvibe_figma_token' );

		return new WP_REST_Response( array(
			'success' => true,
			'message' => 'Figma disconnected.',
		), 200 );
	}

	/**
	 * Fetch frames from a Figma file URL.
	 */
	public function handle_get_figma_frames( WP_REST_Request $request ): WP_REST_Response {
		$file_url = $request->get_param( 'file_url' );
		$token    = $this->key_storage->get_figma_token();

		if ( '' === $token ) {
			return new WP_REST_Response(
				array( 'message' => 'Figma is not configured. Add your token in Settings.' ),
				400
			);
		}

		$figma  = $this->get_figma_client();
		$parsed = $figma->parse_figma_url( $file_url ?? '' );

		if ( null === $parsed ) {
			return new WP_REST_Response(
				array( 'message' => 'Invalid Figma URL.' ),
				400
			);
		}

		$file_info = $figma->get_file_info( $parsed['file_key'], $token );

		if ( null === $file_info ) {
			return new WP_REST_Response(
				array( 'message' => 'Could not fetch Figma file. Check the URL and try again.' ),
				400
			);
		}

		return new WP_REST_Response( array(
			'fileName' => $file_info['name'],
			'frames'   => $file_info['frames'],
		), 200 );
	}

	/**
	 * Get full Figma context (screenshots + design tokens) for selected frames.
	 */
	public function handle_get_figma_context( WP_REST_Request $request ): WP_REST_Response {
		$file_url  = $request->get_param( 'file_url' );
		$frame_ids = $request->get_param( 'frame_ids' );
		$token     = $this->key_storage->get_figma_token();

		if ( '' === $token ) {
			return new WP_REST_Response(
				array( 'message' => 'Figma is not configured.' ),
				400
			);
		}

		if ( empty( $frame_ids ) || ! is_array( $frame_ids ) ) {
			return new WP_REST_Response(
				array( 'message' => 'At least one frame ID is required.' ),
				400
			);
		}

		// Validate each frame ID format (digits separated by colon or hyphen, e.g. "1:23", "45-67").
		$validated_ids = array();
		foreach ( $frame_ids as $fid ) {
			if ( ! is_string( $fid ) || ! preg_match( '/^[0-9]+[-:][0-9]+$/', $fid ) ) {
				return new WP_REST_Response(
					array( 'message' => 'Invalid frame ID format.' ),
					400
				);
			}
			$validated_ids[] = $fid;
		}
		$frame_ids = $validated_ids;

		$figma  = $this->get_figma_client();
		$parsed = $figma->parse_figma_url( $file_url ?? '' );

		if ( null === $parsed ) {
			return new WP_REST_Response(
				array( 'message' => 'Invalid Figma URL.' ),
				400
			);
		}

		// Get the file info for design tokens and frame names.
		$file_info = $figma->get_file_info( $parsed['file_key'], $token );
		if ( null === $file_info ) {
			return new WP_REST_Response(
				array( 'message' => 'Could not fetch Figma file.' ),
				400
			);
		}

		// Get rendered images for the selected frames.
		$images = $figma->get_frame_images( $parsed['file_key'], $frame_ids, $token );

		// Use the first selected frame for the primary context.
		$primary_id = $frame_ids[0];
		$frame_name = 'Selected Frame';
		foreach ( $file_info['frames'] as $frame ) {
			if ( $frame['id'] === $primary_id ) {
				$frame_name = $frame['name'];
				break;
			}
		}

		// Get the full file data for design token extraction.
		// Note: get_file_info already loads depth=2, which may not include
		// deep children. For v1, extract what's available.
		$design_tokens = array(
			'colors'     => array(),
			'typography' => array(),
			'spacing'    => array(),
		);

		return new WP_REST_Response( array(
			'fileName'      => $file_info['name'],
			'frameName'     => $frame_name,
			'frameImageUrl' => $images[ $primary_id ] ?? '',
			'designTokens'  => $design_tokens,
			'componentTree' => new \stdClass(),
		), 200 );
	}

	// ======================================================================
	// Portal integration handlers
	// ======================================================================

	/**
	 * GET /announcements
	 * Fetch active announcements from the WPVibe Service Portal.
	 */
	/**
	 * GET /theme-files — Return the files for the current theme version.
	 */
	public function handle_get_theme_files( WP_REST_Request $request ): WP_REST_Response {
		$session_id = (int) $request->get_param( 'session_id' );
		$user_id    = get_current_user_id();

		$session = $this->session_manager->get_session( $session_id );
		if ( null === $session || (int) $session['user_id'] !== $user_id ) {
			return new WP_REST_Response( array( 'error' => 'Session not found.' ), 404 );
		}

		$versions = $this->session_manager->get_theme_versions( $session_id );
		if ( empty( $versions ) ) {
			return new WP_REST_Response( array( 'files' => array() ), 200 );
		}

		$version = end( $versions );
		$files   = is_string( $version['files_snapshot'] )
			? json_decode( $version['files_snapshot'], true )
			: ( $version['files_snapshot'] ?? array() );

		return new WP_REST_Response( array( 'files' => $files ?? array() ), 200 );
	}

	/**
	 * POST /save-files — Save edited theme files, create a new version, write to disk.
	 */
	public function handle_save_files( WP_REST_Request $request ): WP_REST_Response {
		$session_id = (int) $request->get_param( 'session_id' );
		$files      = $request->get_param( 'files' );
		$user_id    = get_current_user_id();

		if ( ! is_array( $files ) || empty( $files ) ) {
			return new WP_REST_Response( array( 'error' => 'No files provided.' ), 400 );
		}

		$session = $this->session_manager->get_session( $session_id );
		if ( null === $session || (int) $session['user_id'] !== $user_id ) {
			return new WP_REST_Response( array( 'error' => 'Session not found.' ), 404 );
		}

		// Validate file extensions.
		$allowed_ext = array( 'php', 'css', 'js', 'json', 'html', 'txt', 'svg' );
		foreach ( $files as $file ) {
			$ext = strtolower( pathinfo( $file['path'] ?? '', PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, $allowed_ext, true ) ) {
				return new WP_REST_Response( array( 'error' => "File type not allowed: .{$ext}" ), 400 );
			}
		}

		// Get existing versions to build on.
		$versions   = $this->session_manager->get_theme_versions( $session_id );
		$theme_slug = $session['theme_slug'] ?? '';

		if ( ! empty( $versions ) ) {
			$last_version = end( $versions );
			$theme_slug   = $last_version['theme_slug'] ?? $theme_slug;

			// Merge: start from last version's files, apply edits.
			$existing_files = is_string( $last_version['files_snapshot'] )
				? json_decode( $last_version['files_snapshot'], true )
				: ( $last_version['files_snapshot'] ?? array() );

			$merged = array();
			$edited_paths = array();
			foreach ( $files as $f ) {
				$edited_paths[ $f['path'] ] = $f['content'];
			}
			// Keep existing files, update edited ones.
			foreach ( $existing_files as $ef ) {
				$path = $ef['path'];
				if ( isset( $edited_paths[ $path ] ) ) {
					$merged[] = array( 'path' => $path, 'content' => $edited_paths[ $path ] );
					unset( $edited_paths[ $path ] );
				} else {
					$merged[] = $ef;
				}
			}
			// Add any new files.
			foreach ( $edited_paths as $path => $content ) {
				$merged[] = array( 'path' => $path, 'content' => $content );
			}
			$files = $merged;
		}

		if ( empty( $theme_slug ) ) {
			$theme_slug = 'vb-theme-' . $session_id;
		}

		// Save as new version.
		$version_number = count( $versions ) + 1;
		$this->session_manager->save_theme_version(
			$session_id,
			$version_number,
			$theme_slug,
			$files,
			null
		);

		// Write to disk.
		$this->theme_writer->write_theme( $theme_slug, $files );

		// Generate preview URL.
		$preview_html  = $this->theme_parser->generate_preview_from_files( $files );
		$preview_token = $this->preview_engine->create_preview_token( 0, $theme_slug, $preview_html );
		$preview_url   = $this->preview_engine->get_preview_url( $preview_token );

		return new WP_REST_Response( array(
			'success'       => true,
			'previewUrl'    => $preview_url,
			'versionNumber' => $version_number,
		), 200 );
	}

	public function handle_get_announcements( WP_REST_Request $request ): WP_REST_Response {
		$portal        = new VB_Portal_Client();
		$announcements = $portal->get_announcements();

		return new WP_REST_Response( array( 'announcements' => $announcements ), 200 );
	}

	// ======================================================================
	// Permission callback
	// ======================================================================

	/**
	 * Permission callback: user must have edit_themes capability.
	 */
	public function check_edit_themes( WP_REST_Request $request ): bool {
		return current_user_can( 'edit_themes' );
	}

	// ======================================================================
	// Private helpers
	// ======================================================================

	/**
	 * Sliding-window rate limiter.
	 *
	 * Allows a maximum of 30 chat requests per clock-hour per user.
	 * Uses WordPress transients keyed on user ID + hour.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool True if the request is within limits, false if rate-limited.
	 */
	private function check_rate_limit( int $user_id ): bool {
		$hour_key = 'vb_rate_' . $user_id . '_' . gmdate( 'YmdH' );
		$count    = (int) get_transient( $hour_key );

		/**
		 * Filters the maximum number of chat requests per hour per user.
		 *
		 * @param int $max_requests Default 30.
		 * @param int $user_id      The WordPress user ID being checked.
		 */
		$max_requests = (int) apply_filters( 'wpvibe_rate_limit', 30, $user_id );

		if ( $count >= $max_requests ) {
			VB_Logger::instance()->warning(
				sprintf( 'Rate limit reached for user %d (%d/%d this hour).', $user_id, $count, $max_requests )
			);
			return false;
		}

		set_transient( $hour_key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Set HTTP headers for an SSE (Server-Sent Events) response.
	 *
	 * Cleans any existing output buffers so content streams immediately.
	 */
	private function prepare_sse_headers(): void {
		// Remove any output buffers that WordPress or plugins may have opened.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' ); // Nginx: disable proxy buffering.

		// Prevent WordPress from compressing the response.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	/**
	 * Write a single SSE data line and flush.
	 *
	 * @param array $payload Associative array to be JSON-encoded.
	 */
	private function send_sse_event( array $payload ): void {
		echo 'data: ' . wp_json_encode( $payload ) . "\n\n";

		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
	}

	/**
	 * Get the Figma client instance (lazy-loaded).
	 *
	 * @return VB_Figma_Client
	 */
	private function get_figma_client(): VB_Figma_Client {
		if ( null === $this->figma_client ) {
			$this->figma_client = new VB_Figma_Client();
		}
		return $this->figma_client;
	}

	/**
	 * Build messages array with multimodal content for vision-capable models.
	 *
	 * Transforms messages that have image/Figma attachments into the
	 * provider-specific multimodal format. Only includes base64 image data
	 * for the last 3 user messages with images to manage memory.
	 *
	 * @param array  $history  Message history rows from the database.
	 * @param string $key_type The API key type (determines message format).
	 * @return array Messages array ready for the AI provider.
	 */
	private function build_multimodal_messages( array $history, string $key_type ): array {
		$messages = array();

		// Count messages with image attachments (for memory limit).
		$image_message_indices = array();
		foreach ( $history as $index => $row ) {
			if ( 'user' === $row['role'] && ! empty( $row['attachments'] ) ) {
				$attachments = is_string( $row['attachments'] )
					? json_decode( $row['attachments'], true )
					: $row['attachments'];
				if ( is_array( $attachments ) && ! empty( $attachments ) ) {
					$image_message_indices[] = $index;
				}
			}
		}

		// Only include base64 for the last 3 messages with images.
		$include_images_from = array_slice( $image_message_indices, -3 );

		// Find the last assistant message index to keep it in full.
		$last_assistant_index = null;
		foreach ( $history as $index => $row ) {
			if ( 'assistant' === $row['role'] ) {
				$last_assistant_index = $index;
			}
		}

		foreach ( $history as $index => $row ) {
			if ( 'system' === $row['role'] ) {
				continue;
			}

			$attachments = null;
			if ( ! empty( $row['attachments'] ) ) {
				$attachments = is_string( $row['attachments'] )
					? json_decode( $row['attachments'], true )
					: $row['attachments'];
			}

			$has_images = is_array( $attachments ) && ! empty( $attachments );
			$include_base64 = in_array( $index, $include_images_from, true );

			if ( 'user' === $row['role'] && $has_images && $include_base64 ) {
				$messages[] = array(
					'role'    => $row['role'],
					'content' => $this->format_multimodal_content(
						$row['content'],
						$attachments,
						$key_type
					),
				);
			} elseif ( 'user' === $row['role'] && $has_images && ! $include_base64 ) {
				// Older image messages: replace images with text placeholder.
				$messages[] = array(
					'role'    => $row['role'],
					'content' => $row['content'] . "\n\n[Reference images were attached to this message]",
				);
			} elseif ( 'assistant' === $row['role'] && $index !== $last_assistant_index ) {
				// Compress older assistant messages: extract summary, skip file contents.
				$messages[] = array(
					'role'    => 'assistant',
					'content' => $this->compress_assistant_message( $row['content'] ),
				);
			} else {
				$messages[] = array(
					'role'    => $row['role'],
					'content' => $row['content'],
				);
			}
		}

		// Anthropic requires strictly alternating user/assistant roles.
		// Merge consecutive same-role messages and remove empties.
		$merged = array();
		foreach ( $messages as $msg ) {
			$content = $msg['content'] ?? '';

			// Skip empty messages.
			if ( is_string( $content ) && '' === trim( $content ) ) {
				continue;
			}
			if ( is_array( $content ) && empty( $content ) ) {
				continue;
			}

			if ( ! empty( $merged ) && $merged[ count( $merged ) - 1 ]['role'] === $msg['role'] ) {
				// Merge with the previous message of the same role.
				$prev = &$merged[ count( $merged ) - 1 ];
				if ( is_string( $prev['content'] ) && is_string( $content ) ) {
					$prev['content'] .= "\n\n" . $content;
				} elseif ( is_array( $prev['content'] ) && is_string( $content ) ) {
					$prev['content'][] = array( 'type' => 'text', 'text' => $content );
				} elseif ( is_string( $prev['content'] ) && is_array( $content ) ) {
					$prev['content'] = array_merge(
						array( array( 'type' => 'text', 'text' => $prev['content'] ) ),
						$content
					);
				} elseif ( is_array( $prev['content'] ) && is_array( $content ) ) {
					$prev['content'] = array_merge( $prev['content'], $content );
				}
				unset( $prev );
			} else {
				$merged[] = $msg;
			}
		}

		return $merged;
	}

	/**
	 * Compress an older assistant JSON response to a brief summary.
	 *
	 * Extracts the "message", "changes_summary", and file paths from the
	 * structured JSON, discarding the full file contents and preview_html
	 * to save context window space.
	 *
	 * @param string $content The raw assistant message content.
	 * @return string Compressed summary string.
	 */
	private function compress_assistant_message( string $content ): string {
		$json_str = trim( $content );

		// Strip markdown code fences.
		if ( str_starts_with( $json_str, '```' ) ) {
			$json_str = preg_replace( '/^```(?:json)?\s*\n?/', '', $json_str );
			$json_str = preg_replace( '/\n?```\s*$/', '', $json_str );
		}

		$data = json_decode( $json_str, true );

		if ( ! is_array( $data ) ) {
			// Not JSON — return as-is but truncated.
			return mb_substr( $content, 0, 500 );
		}

		$parts = array();

		if ( ! empty( $data['message'] ) ) {
			$parts[] = $data['message'];
		}

		if ( ! empty( $data['changes_summary'] ) && is_array( $data['changes_summary'] ) ) {
			$parts[] = 'Changes: ' . implode( ', ', $data['changes_summary'] );
		}

		if ( ! empty( $data['files'] ) && is_array( $data['files'] ) ) {
			$file_paths = array_map(
				static fn( $f ) => $f['path'] ?? '?',
				$data['files']
			);
			$parts[] = 'Files modified: ' . implode( ', ', $file_paths );
		}

		return ! empty( $parts )
			? implode( "\n", $parts )
			: mb_substr( $content, 0, 500 );
	}

	/**
	 * Build a theme context summary to inject into conversation.
	 *
	 * Reads the current theme version's files from the last snapshot and
	 * produces a structured overview so the AI knows exactly what files
	 * exist, their sizes, and key content markers.
	 *
	 * @param int   $session_id Current session ID.
	 * @param array $session    Session row from the database.
	 * @return string Context string, or empty string if no theme exists.
	 */
	private function build_theme_context( int $session_id, array $session ): string {
		$versions = $this->session_manager->get_theme_versions( $session_id );
		if ( empty( $versions ) ) {
			return '';
		}

		$last_version = end( $versions );
		$files_snapshot = is_string( $last_version['files_snapshot'] )
			? json_decode( $last_version['files_snapshot'], true )
			: ( $last_version['files_snapshot'] ?? array() );

		if ( ! is_array( $files_snapshot ) || empty( $files_snapshot ) ) {
			return '';
		}

		$theme_slug = $session['theme_slug'] ?? 'unknown';
		$parts      = array();
		$parts[]    = "[CURRENT THEME STATE — \"{$theme_slug}\" — version {$last_version['version_number']}]";
		$parts[]    = 'The following theme files already exist. Only return files you need to ADD or MODIFY. Do NOT regenerate unchanged files.';
		$parts[]    = '';

		foreach ( $files_snapshot as $file ) {
			$path    = $file['path'] ?? '?';
			$content = $file['content'] ?? '';
			$size    = strlen( $content );
			$lines   = substr_count( $content, "\n" ) + 1;

			// Show a brief excerpt of each file (first ~20 lines).
			$excerpt_lines = array_slice( explode( "\n", $content ), 0, 20 );
			$excerpt       = implode( "\n", $excerpt_lines );
			if ( $lines > 20 ) {
				$excerpt .= "\n... ({$lines} total lines)";
			}

			$parts[] = "--- {$path} ({$size} bytes, {$lines} lines) ---";
			$parts[] = $excerpt;
			$parts[] = '';
		}

		return implode( "\n", $parts );
	}

	/**
	 * Format a message's content as a multimodal content array.
	 *
	 * Converts image and Figma attachments into the provider-specific format
	 * (Anthropic or OpenAI) alongside the text content.
	 *
	 * @param string $text        The text content of the message.
	 * @param array  $attachments The message attachments.
	 * @param string $key_type    The API key type.
	 * @return array Multimodal content array.
	 */
	private function format_multimodal_content( string $text, array $attachments, string $key_type ): array {
		$content   = array();
		$is_claude = in_array( $key_type, array( 'claude_api', 'claude_oauth' ), true );

		foreach ( $attachments as $attachment ) {
			$type = $attachment['type'] ?? '';

			if ( 'image' === $type ) {
				$image_data = $this->get_image_base64( $attachment );
				if ( null === $image_data ) {
					continue;
				}

				if ( $is_claude ) {
					$content[] = array(
						'type'   => 'image',
						'source' => array(
							'type'       => 'base64',
							'media_type' => $image_data['media_type'],
							'data'       => $image_data['base64'],
						),
					);
				} else {
					$content[] = array(
						'type'      => 'image_url',
						'image_url' => array(
							'url' => 'data:' . $image_data['media_type'] . ';base64,' . $image_data['base64'],
						),
					);
				}
			} elseif ( 'figma' === $type ) {
				$figma_context = $attachment['context'] ?? null;
				if ( null === $figma_context ) {
					continue;
				}

				// Add frame screenshot as image if available.
				if ( ! empty( $figma_context['frameImageUrl'] ) ) {
					if ( $is_claude ) {
						$content[] = array(
							'type'   => 'image',
							'source' => array(
								'type'       => 'base64',
								'media_type' => 'image/png',
								'data'       => $figma_context['frameImageUrl'],
							),
						);
					} else {
						$content[] = array(
							'type'      => 'image_url',
							'image_url' => array(
								'url' => 'data:image/png;base64,' . $figma_context['frameImageUrl'],
							),
						);
					}
				}

				// Add design tokens and metadata as text context.
				$figma_text = "FIGMA DESIGN CONTEXT:\n";
				$figma_text .= 'File: ' . ( $figma_context['fileName'] ?? 'Unknown' ) . "\n";
				$figma_text .= 'Frame: ' . ( $figma_context['frameName'] ?? 'Unknown' ) . "\n";
				if ( ! empty( $figma_context['designTokens'] ) ) {
					$figma_text .= 'Design Tokens: ' . wp_json_encode( $figma_context['designTokens'] ) . "\n";
				}
				$content[] = array(
					'type' => 'text',
					'text' => $figma_text,
				);
			}
		}

		// Add the user's text content last.
		if ( '' !== $text ) {
			$content[] = array(
				'type' => 'text',
				'text' => $text,
			);
		}

		return $content;
	}

	/**
	 * Maximum base64 size for AI provider image uploads (bytes).
	 * Anthropic limits images to 5 MB base64. We target ~3.5 MB to be safe.
	 */
	private const MAX_IMAGE_BASE64_BYTES = 3_500_000;

	/**
	 * Read an uploaded image from the WordPress media library and return as base64.
	 *
	 * If the image exceeds the provider's size limit, it is automatically
	 * resized down using GD or Imagick (whichever WP has available).
	 *
	 * @param array $attachment The image attachment data.
	 * @return array|null Array with 'base64' and 'media_type' keys, or null on failure.
	 */
	private function get_image_base64( array $attachment ): ?array {
		$attachment_id = isset( $attachment['id'] ) ? (int) $attachment['id'] : 0;

		if ( $attachment_id > 0 ) {
			$file_path = get_attached_file( $attachment_id );
			if ( $file_path && file_exists( $file_path ) ) {
				$media_type = $attachment['mediaType'] ?? mime_content_type( $file_path );
				$media_type = $media_type ?: 'image/png';

				$file_contents = file_get_contents( $file_path );
				if ( false === $file_contents ) {
					return null;
				}

				$base64 = base64_encode( $file_contents );

				// If base64 exceeds the provider limit, resize the image.
				if ( strlen( $base64 ) > self::MAX_IMAGE_BASE64_BYTES ) {
					$resized = $this->resize_image_for_api( $file_path, $media_type );
					if ( null !== $resized ) {
						return $resized;
					}
					// Resize failed — fall through with original (will likely error).
				}

				return array(
					'base64'     => $base64,
					'media_type' => $media_type,
				);
			}
		}

		// Fallback: base64 was passed directly (e.g. Figma screenshots).
		if ( ! empty( $attachment['base64'] ) ) {
			return array(
				'base64'     => $attachment['base64'],
				'media_type' => $attachment['mediaType'] ?? 'image/png',
			);
		}

		return null;
	}

	/**
	 * Resize an image so its base64 representation fits within the API limit.
	 *
	 * Progressively scales the image down until the base64 size is acceptable.
	 * Uses WordPress's WP_Image_Editor (GD or Imagick).
	 *
	 * @param string $file_path  Absolute path to the image file.
	 * @param string $media_type Original MIME type.
	 * @return array|null Array with 'base64' and 'media_type' keys, or null on failure.
	 */
	private function resize_image_for_api( string $file_path, string $media_type ): ?array {
		$editor = wp_get_image_editor( $file_path );
		if ( is_wp_error( $editor ) ) {
			VB_Logger::instance()->warning( 'Image resize failed: ' . $editor->get_error_message() );
			return null;
		}

		$size = $editor->get_size();
		if ( ! $size ) {
			return null;
		}

		$width  = $size['width'];
		$height = $size['height'];

		// Output as JPEG for smaller file size (unless it's a PNG with transparency needs).
		$output_type = 'image/jpeg';
		$quality     = 85;

		// Try progressively smaller sizes.
		$max_dimensions = array( 2048, 1536, 1024, 768 );

		foreach ( $max_dimensions as $max_dim ) {
			if ( $width <= $max_dim && $height <= $max_dim ) {
				continue;
			}

			$editor = wp_get_image_editor( $file_path );
			if ( is_wp_error( $editor ) ) {
				return null;
			}

			$editor->resize( $max_dim, $max_dim );
			$editor->set_quality( $quality );

			$temp_file = tempnam( sys_get_temp_dir(), 'vb_resize_' );
			$saved     = $editor->save( $temp_file, $output_type );

			if ( is_wp_error( $saved ) ) {
				@unlink( $temp_file );
				continue;
			}

			$actual_path = $saved['path'];
			$contents    = file_get_contents( $actual_path );
			@unlink( $actual_path );
			if ( $actual_path !== $temp_file ) {
				@unlink( $temp_file );
			}

			if ( false === $contents ) {
				continue;
			}

			$base64 = base64_encode( $contents );
			if ( strlen( $base64 ) <= self::MAX_IMAGE_BASE64_BYTES ) {
				VB_Logger::instance()->info(
					"Image resized to {$max_dim}px max dimension, base64 size: " . strlen( $base64 ) . ' bytes'
				);
				return array(
					'base64'     => $base64,
					'media_type' => $output_type,
				);
			}
		}

		// Last resort: 768px with lower quality.
		$editor = wp_get_image_editor( $file_path );
		if ( is_wp_error( $editor ) ) {
			return null;
		}

		$editor->resize( 768, 768 );
		$editor->set_quality( 60 );

		$temp_file = tempnam( sys_get_temp_dir(), 'vb_resize_' );
		$saved     = $editor->save( $temp_file, 'image/jpeg' );

		if ( is_wp_error( $saved ) ) {
			@unlink( $temp_file );
			return null;
		}

		$actual_path = $saved['path'];
		$contents    = file_get_contents( $actual_path );
		@unlink( $actual_path );
		if ( $actual_path !== $temp_file ) {
			@unlink( $temp_file );
		}

		if ( false === $contents ) {
			return null;
		}

		VB_Logger::instance()->info(
			'Image resized to 768px/q60, base64 size: ' . strlen( base64_encode( $contents ) ) . ' bytes'
		);

		return array(
			'base64'     => base64_encode( $contents ),
			'media_type' => 'image/jpeg',
		);
	}

	/**
	 * Generate the select-mode listener script for preview iframes.
	 *
	 * This script listens for postMessage commands from the parent window
	 * to enable/disable select mode, replacing direct script injection.
	 *
	 * @param string $nonce CSP nonce for the script tag.
	 * @return string HTML script tag with the listener.
	 */
	private function get_select_mode_listener_script( string $nonce ): string {
		$origin = esc_js( home_url() );

		return '<script nonce="' . esc_attr( $nonce ) . '">'
			. '(function(){'
			. 'var overlay=null,lastTarget=null,selectMode=false;'
			. 'function getLabel(el){'
			.   'if(el.id)return"#"+el.id;'
			.   'var a=el.getAttribute("aria-label");if(a)return a;'
			.   'var h=el.querySelector("h1,h2,h3");'
			.   'if(h&&h.textContent.trim())return h.textContent.trim().substring(0,40);'
			.   'var c=Array.from(el.classList).slice(0,2).join(".");'
			.   'return el.tagName.toLowerCase()+(c?"."+c:"");'
			. '}'
			. 'function getSelector(el){'
			.   'var p=[],c=el;'
			.   'while(c&&c!==document.body&&p.length<4){'
			.     'var t=c.tagName.toLowerCase();'
			.     'if(c.id){p.unshift(t+"#"+c.id);break;}'
			.     'var cl=Array.from(c.classList).slice(0,2).join(".");'
			.     'p.unshift(t+(cl?"."+cl:""));c=c.parentElement;'
			.   '}'
			.   'return p.join(" > ");'
			. '}'
			. 'function findSection(el){'
			.   'var tags=["SECTION","HEADER","FOOTER","NAV","MAIN","ARTICLE","ASIDE","DIV"];'
			.   'var c=el;'
			.   'while(c&&c!==document.body){'
			.     'if(tags.indexOf(c.tagName)!==-1){'
			.       'if(c.tagName!=="DIV"||c.parentElement===document.body||c.classList.length>0)return c;'
			.     '}'
			.     'c=c.parentElement;'
			.   '}'
			.   'return el;'
			. '}'
			. 'function showOverlay(el){'
			.   'if(!overlay){overlay=document.createElement("div");'
			.   'overlay.style.cssText="position:absolute;pointer-events:none;border:2px solid #6366f1;background:rgba(99,102,241,0.08);border-radius:4px;z-index:999999;transition:all 0.15s ease;";'
			.   'document.body.appendChild(overlay);}'
			.   'var r=el.getBoundingClientRect();'
			.   'overlay.style.top=(r.top+window.scrollY)+"px";'
			.   'overlay.style.left=(r.left+window.scrollX)+"px";'
			.   'overlay.style.width=r.width+"px";'
			.   'overlay.style.height=r.height+"px";'
			.   'overlay.style.display="block";'
			. '}'
			. 'function hideOverlay(){if(overlay)overlay.style.display="none";}'
			. 'function onMove(e){var s=findSection(e.target);if(s!==lastTarget){lastTarget=s;showOverlay(s);}}'
			. 'function onLeave(){hideOverlay();lastTarget=null;}'
			. 'function onClick(e){'
			.   'e.preventDefault();e.stopPropagation();'
			.   'var s=findSection(e.target);'
			.   'var snip=s.outerHTML;'
			.   'if(snip.length>500){var inner=s.innerHTML;snip=s.outerHTML.substring(0,s.outerHTML.indexOf(inner))+"...</"+s.tagName.toLowerCase()+">";}'
			.   'window.parent.postMessage({type:"vb-section-selected",data:{selector:getSelector(s),tagName:s.tagName,label:getLabel(s),outerHtmlSnippet:snip.substring(0,500)}},"' . $origin . '");'
			. '}'
			. 'window.addEventListener("message",function(e){'
			.   'if(e.origin!=="' . $origin . '")return;'
			.   'if(e.data&&e.data.type==="vb-select-mode"){'
			.     'if(e.data.active&&!selectMode){'
			.       'selectMode=true;'
			.       'document.addEventListener("mousemove",onMove,true);'
			.       'document.addEventListener("mouseleave",onLeave,true);'
			.       'document.addEventListener("click",onClick,true);'
			.     '}else if(!e.data.active&&selectMode){'
			.       'selectMode=false;'
			.       'document.removeEventListener("mousemove",onMove,true);'
			.       'document.removeEventListener("mouseleave",onLeave,true);'
			.       'document.removeEventListener("click",onClick,true);'
			.       'hideOverlay();lastTarget=null;'
			.       'if(overlay){overlay.remove();overlay=null;}'
			.     '}'
			.   '}'
			. '});'
			. '})();'
			. '</script>';
	}
}
