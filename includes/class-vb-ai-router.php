<?php
/**
 * AI provider router.
 *
 * Determines the configured key type and routes streaming requests
 * to the appropriate provider client (Anthropic, OpenAI, or LiteLLM).
 *
 * @package WPVibe
 */

defined( 'ABSPATH' ) || exit;

class VB_AI_Router {

	private VB_Key_Storage   $key_storage;
	private VB_Anthropic     $anthropic;
	private VB_OpenAI        $openai;
	private VB_LiteLLM       $litellm;
	private VB_Portal_Client $portal;

	/**
	 * @param VB_Key_Storage $key_storage Encrypted key storage instance.
	 */
	public function __construct( VB_Key_Storage $key_storage ) {
		$this->key_storage = $key_storage;
		$this->anthropic   = new VB_Anthropic();
		$this->openai      = new VB_OpenAI();
		$this->litellm     = new VB_LiteLLM();
		$this->portal      = new VB_Portal_Client();
	}

	/**
	 * Stream an AI response to the output buffer (SSE).
	 *
	 * Each provider's `stream()` method disables output buffering, sets SSE
	 * headers, and writes `data: {json}\n\n` lines directly to php://output.
	 *
	 * @param array       $messages      Conversation messages array.
	 * @param string      $model         Model identifier to use.
	 * @param string|null $system_prompt Optional system prompt override.
	 */
	public function stream_response(
		array $messages,
		string $model,
		?string $system_prompt = null,
		string &$response_buffer = '',
	): void {
		$key_type = $this->key_storage->get_key_type();
		$api_key  = $this->key_storage->get_key();

		if ( empty( $api_key ) || empty( $key_type ) ) {
			$this->send_sse_error( __( 'No API key configured. Please add a key in WPVibe settings.', 'wpvibe' ) );
			return;
		}

		if ( null === $system_prompt ) {
			$system_prompt = $this->get_system_prompt();
		}

		try {
			match ( $key_type ) {
				'claude_api'          => $this->anthropic->stream(
					$messages,
					$model,
					$system_prompt,
					$api_key,
					'api_key',
				),
				'claude_oauth'        => $this->anthropic->stream(
					$messages,
					$model,
					$system_prompt,
					$api_key,
					'oauth',
				),
				'openai_codex'        => $this->openai->stream(
					$messages,
					$model,
					$system_prompt,
					$api_key,
				),
				'wpvibe_service' => $this->litellm->stream(
					$messages,
					$model,
					$system_prompt,
					$this->get_litellm_key( $api_key ),
					16384,
					$this->get_key_tag( $api_key ),
				),
				default               => $this->send_sse_error(
					/* translators: %s: the unrecognised key type string. */
					sprintf( __( 'Unknown API key type: %s. Please reconfigure your key.', 'wpvibe' ), $key_type )
				),
			};

			// Collect accumulated response text from the active provider.
			$response_buffer = match ( $key_type ) {
				'claude_api', 'claude_oauth' => $this->anthropic->get_response_text(),
				'openai_codex'               => $this->openai->get_response_text(),
				'wpvibe_service'        => $this->litellm->get_response_text(),
				default                      => '',
			};
		} catch ( \Throwable $e ) {
			VB_Logger::instance()->error( 'AI streaming error', $e );
			$this->send_sse_error( __( 'An error occurred while communicating with the AI provider. Please try again.', 'wpvibe' ) );
		}
	}

	/**
	 * Return the default WPVibe theme-generation system prompt.
	 *
	 * This prompt instructs the AI to produce structured JSON output that
	 * the plugin can parse into theme files and a live preview.
	 */
	public function get_system_prompt(): string {
		// Check for a remotely managed system prompt from the Service Portal.
		$remote_prompt = $this->portal->get_system_prompt();
		if ( null !== $remote_prompt && '' !== $remote_prompt ) {
			/** This filter is documented below. */
			return apply_filters( 'wpvibe_system_prompt', $remote_prompt );
		}

		$prompt = <<<'PROMPT'
You are WP Vibe, an expert WordPress theme developer AI assistant.

RESPONSE FORMAT — PURE JSON only (no markdown fences, no text outside the JSON):
{
  "message": "Brief explanation",
  "changes_summary": ["Change 1", "Change 2"],
  "files": [
    {"path": "style.css", "content": "..."},
    {"path": "header.php", "content": "..."},
    {"path": "index.php", "content": "..."},
    {"path": "footer.php", "content": "..."},
    {"path": "functions.php", "content": "..."}
  ],
  "preview_html": ""
}

HARD SIZE LIMITS — CRITICAL:
- Total response MUST stay under 16000 tokens. This is a hard limit.
- CSS: use shorthand, combine selectors, minimal comments. No verbose resets.
- HTML: clean and semantic, no excessive nesting.
- Do NOT include theme.json, screenshot.png, or extra template files unless asked.
- Do NOT include JavaScript animations, Three.js, particles, or heavy libraries.
- Set "preview_html" to empty string "" — the system generates previews automatically from your files.
- ALWAYS output keys in this order: message, changes_summary, files, preview_html.

REQUIRED FILES (for new themes):
Always generate these core files:
- style.css: Theme header comment (required by WordPress).
- functions.php: Theme setup, nav menus, widget areas, enqueues (including Tailwind CDN).
- header.php: <!DOCTYPE>, <html>, <head> with wp_head(), <body>, site header with navigation.
- footer.php: Site footer, wp_footer(), </body></html>.
- index.php: Main template with get_header()/get_footer().
- front-page.php: Homepage template.

MULTI-PAGE THEMES:
Analyze the user's prompt to determine if they need a multi-page theme.
- If the user describes multiple pages (e.g. "portfolio site with about, services, contact pages") or a complex site, generate additional templates: page.php, single.php, archive.php, 404.php, and any custom page templates needed.
- If the user describes a simple landing page or single-page site, only generate the core files above.
- Always register at least one nav menu ('primary') and use wp_nav_menu() in header.php.
- For custom page templates, use the WordPress template header comment: /* Template Name: About */

INCREMENTAL EDITING:
- When CURRENT THEME STATE is provided, only return files you are CHANGING.
- Return COMPLETE file content for changed files (not diffs).
- The system merges changed files with existing files automatically.

SECTION-SPECIFIC EDITING:
- When a message starts with [EDIT SECTION: ...], the user has clicked on a specific section in the live preview.
- The HTML snippet of the selected section is provided for context.
- ONLY modify the code for that specific section. Do NOT change other parts of the theme.
- Return the complete file(s) containing the modified section.

STYLING:
%%CSS_FRAMEWORK_INSTRUCTIONS%%

SECURITY — CRITICAL (non-negotiable):
- ALWAYS escape ALL output: esc_html(), esc_attr(), esc_url(), wp_kses_post().
- NEVER echo raw variables — always use escaping wrappers.
- Use esc_url() for ALL href/src attributes.
- Use esc_attr() for ALL HTML attribute values.
- Use wp_nonce_field() / wp_verify_nonce() for any forms.
- NEVER use code-execution or OS-command functions.
- NEVER access superglobal arrays directly — use WordPress sanitization.
- NEVER use extract() on untrusted data. NEVER output unfiltered user input.
- Use sanitize_text_field(), absint(), sanitize_email() for input handling.
- Use $wpdb->prepare() for any database queries.
- NEVER use unsafe JS patterns: no inline event handlers with dynamic data, no unsafe DOM writes.
- Use textContent or createElement() for DOM manipulation in JavaScript.

THEME STANDARDS:
- WordPress 6.3+, mobile-first responsive, WCAG 2.1 AA
- wp_enqueue_style/wp_enqueue_script (never hardcode <link>/<script>)
%%CSS_FRAMEWORK_ENQUEUE%%
- Keep designs clean, modern, and professional.
PROMPT;

		$css_framework = get_option( 'wpvibe_css_framework', 'tailwind' );

		$css_instructions = match ( $css_framework ) {
			'bootstrap' => implode( "\n", array(
				'- Use Bootstrap 5 for all styling (via CDN in functions.php).',
				'- Use Bootstrap utility classes and components in HTML/PHP templates.',
				'- Only write custom CSS when Bootstrap cannot handle it.',
				'- Only use Tailwind or other CSS frameworks if the user explicitly requests it.',
			) ),
			'vanilla' => implode( "\n", array(
				'- Use custom vanilla CSS for all styling (written in style.css).',
				'- Use CSS custom properties for design tokens (colors, spacing, typography).',
				'- Use modern CSS: flexbox, grid, container queries where appropriate.',
				'- Do NOT use Tailwind, Bootstrap, or any CSS framework unless the user explicitly requests it.',
			) ),
			default => implode( "\n", array(
				'- Use Tailwind CSS by default for all styling (via CDN in functions.php).',
				'- Only use Bootstrap or other CSS frameworks if the user explicitly requests it.',
				'- When using Tailwind, use utility classes directly in HTML/PHP templates.',
				'- Do NOT write custom CSS in style.css beyond the required theme header comment, unless Tailwind cannot handle it.',
			) ),
		};

		$css_enqueue = match ( $css_framework ) {
			'bootstrap' => "- Enqueue Bootstrap CSS+JS CDN in functions.php: wp_enqueue_style('bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'); wp_enqueue_script('bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js', array(), null, true);",
			'vanilla'   => '- Write all custom styles in style.css using modern CSS. No external CSS frameworks to enqueue.',
			default     => "- Enqueue Tailwind CSS CDN play script in functions.php: wp_enqueue_script('tailwindcss', 'https://cdn.tailwindcss.com', array(), null, false);",
		};

		$prompt = str_replace( '%%CSS_FRAMEWORK_INSTRUCTIONS%%', $css_instructions, $prompt );
		$prompt = str_replace( '%%CSS_FRAMEWORK_ENQUEUE%%', $css_enqueue, $prompt );

		/**
		 * Filters the WPVibe system prompt sent with every AI request.
		 *
		 * @param string $prompt The default system prompt.
		 */
		return apply_filters( 'wpvibe_system_prompt', $prompt );
	}

	/**
	 * Make a non-streaming AI completion and return the full response text.
	 *
	 * Routes to the appropriate provider's `complete()` method based on the
	 * configured key type. Used for secondary AI calls (e.g. security scanning)
	 * where streaming to the client is not needed.
	 *
	 * @param array       $messages      Conversation messages array.
	 * @param string      $model         Model identifier to use.
	 * @param string|null $system_prompt System prompt (uses default if null).
	 * @return string The full AI response text.
	 *
	 * @throws \RuntimeException If no key is configured or the provider call fails.
	 */
	public function complete_response(
		array $messages,
		string $model,
		?string $system_prompt = null,
	): string {
		$key_type = $this->key_storage->get_key_type();
		$api_key  = $this->key_storage->get_key();

		if ( empty( $api_key ) || empty( $key_type ) ) {
			throw new \RuntimeException(
				__( 'No API key configured. Please add a key in WPVibe settings.', 'wpvibe' )
			);
		}

		if ( null === $system_prompt ) {
			$system_prompt = $this->get_system_prompt();
		}

		return match ( $key_type ) {
			'claude_api'          => $this->anthropic->complete(
				$messages, $model, $system_prompt, $api_key, 'api_key',
			),
			'claude_oauth'        => $this->anthropic->complete(
				$messages, $model, $system_prompt, $api_key, 'oauth',
			),
			'openai_codex'        => $this->openai->complete(
				$messages, $model, $system_prompt, $api_key,
			),
			'wpvibe_service' => $this->litellm->complete(
				$messages, $model, $system_prompt, $this->get_litellm_key( $api_key ),
				8192, $this->get_key_tag( $api_key ),
			),
			default               => throw new \RuntimeException(
				sprintf( __( 'Unknown API key type: %s', 'wpvibe' ), $key_type )
			),
		};
	}

	/**
	 * Get the LiteLLM proxy key for service key users.
	 *
	 * If no proxy key is stored locally, re-validates the service key
	 * against the portal to fetch one. Throws if a proxy key cannot
	 * be obtained — never falls back to the raw vb_live_* key.
	 *
	 * @param string $service_key The vb_live_* service key.
	 * @return string The LiteLLM-compatible sk-* key.
	 *
	 * @throws \RuntimeException If a proxy key cannot be obtained.
	 */
	private function get_litellm_key( string $service_key ): string {
		$proxy_key = $this->key_storage->get_proxy_key();

		if ( ! empty( $proxy_key ) ) {
			return $proxy_key;
		}

		// No proxy key stored — re-validate to fetch one from the portal.
		$result = $this->portal->validate_key( $service_key );

		if ( ! empty( $result['valid'] ) ) {
			$proxy_key = $this->key_storage->get_proxy_key();
			if ( ! empty( $proxy_key ) ) {
				return $proxy_key;
			}
		}

		throw new \RuntimeException(
			__( 'Unable to obtain a LiteLLM proxy key. Please re-validate your WPVibe service key in Settings.', 'wpvibe' )
		);
	}

	/**
	 * Get a tag from the vb_live_* key prefix for LiteLLM request tracking.
	 *
	 * @param string $api_key The vb_live_* key.
	 * @return string The key prefix tag (e.g. "vb_live_a1b2").
	 */
	private function get_key_tag( string $api_key ): string {
		return substr( $api_key, 0, 12 );
	}

	/**
	 * Send a single SSE error event and return.
	 *
	 * @param string $message Human-readable error message.
	 */
	private function send_sse_error( string $message ): void {
		$payload = wp_json_encode(
			array(
				'type'    => 'error',
				'content' => $message,
			)
		);

		echo 'data: ' . $payload . "\n\n";

		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
	}
}
