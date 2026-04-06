<?php
/**
 * LiteLLM proxy client with SSE streaming support.
 *
 * Routes requests through the WPVibe LiteLLM proxy server,
 * which uses an OpenAI-compatible API format. This client handles
 * WPVibe service keys (vb_live_*, vb_test_*).
 *
 * @package WPVibe
 */

defined( 'ABSPATH' ) || exit;

class VB_LiteLLM {

	/**
	 * Default LiteLLM proxy endpoint.
	 */
	private const DEFAULT_ENDPOINT = 'https://llm.wpvibe.net/chat/completions';

	/**
	 * Maximum cURL timeout in seconds.
	 */
	private const CURL_TIMEOUT = 300;

	/**
	 * Buffer for accumulating partial SSE lines from cURL chunks.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Raw response for error extraction after cURL completes.
	 *
	 * @var string
	 */
	private string $raw_response = '';

	/**
	 * Accumulated full response text from text_delta events.
	 *
	 * @var string
	 */
	private string $response_text = '';

	/**
	 * Get the configured LiteLLM endpoint URL.
	 *
	 * Falls back to the default WPVibe proxy endpoint if no custom
	 * endpoint has been configured in the plugin settings.
	 *
	 * @return string The full chat completions endpoint URL.
	 */
	private function get_endpoint(): string {
		$custom_endpoint = get_option( 'wpvibe_litellm_endpoint', '' );

		if ( '' !== $custom_endpoint ) {
			// Validate the custom endpoint URL to prevent SSRF.
			if ( ! $this->is_safe_url( $custom_endpoint ) ) {
				VB_Logger::instance()->warning( 'LiteLLM endpoint failed safety validation, using default.' );
				return self::DEFAULT_ENDPOINT;
			}

			// Log when using a non-default endpoint (bearer token will be sent there).
			$parsed_host = wp_parse_url( $custom_endpoint, PHP_URL_HOST );
			$default_host = wp_parse_url( self::DEFAULT_ENDPOINT, PHP_URL_HOST );
			if ( $parsed_host !== $default_host ) {
				VB_Logger::instance()->info(
					'Using custom LiteLLM endpoint: ' . sanitize_text_field( $parsed_host )
					. ' — bearer token will be sent to this host.'
				);
			}

			// Ensure the custom endpoint ends with /chat/completions.
			$custom_endpoint = rtrim( $custom_endpoint, '/' );
			if ( ! str_ends_with( $custom_endpoint, '/chat/completions' ) ) {
				$custom_endpoint .= '/chat/completions';
			}
			return $custom_endpoint;
		}

		return self::DEFAULT_ENDPOINT;
	}

	/**
	 * Validate a URL to prevent SSRF attacks.
	 *
	 * Blocks private/reserved IPs, non-HTTPS schemes, and non-standard ports.
	 *
	 * @param string $url URL to validate.
	 * @return bool Whether the URL is considered safe.
	 */
	private function is_safe_url( string $url ): bool {
		$parsed = wp_parse_url( $url );

		if ( ! $parsed || empty( $parsed['host'] ) ) {
			return false;
		}

		// Must use HTTPS.
		$scheme = strtolower( $parsed['scheme'] ?? '' );
		if ( 'https' !== $scheme ) {
			return false;
		}

		$host = strtolower( $parsed['host'] );

		// Block localhost and loopback.
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' ), true ) ) {
			return false;
		}

		// Block private and reserved IP ranges.
		$ip = gethostbyname( $host );
		if ( $ip !== $host && ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		// Block cloud metadata endpoints.
		if ( str_contains( $host, '169.254.' ) || '169.254.169.254' === $ip ) {
			return false;
		}

		return true;
	}

	/**
	 * Stream a chat completion from the LiteLLM proxy.
	 *
	 * The LiteLLM proxy uses OpenAI-compatible request and response formats.
	 * Opens a cURL connection with WRITEFUNCTION streaming and forwards
	 * parsed SSE events to the client in the normalized WPVibe format.
	 *
	 * @param array  $messages      Array of message objects with 'role' and 'content' keys.
	 * @param string $model         LiteLLM model alias (e.g. 'claude-sonnet-fast', 'gpt-4o-latest').
	 * @param string $system_prompt System prompt prepended as a system message.
	 * @param string $api_key       WPVibe service key (vb_live_* or vb_test_*).
	 * @param int    $max_tokens    Maximum tokens in the response.
	 */
	public function stream(
		array $messages,
		string $model,
		string $system_prompt,
		string $api_key,
		int $max_tokens = 16384,
		string $request_tag = '',
	): void {
		$this->buffer        = '';
		$this->response_text = '';
		$this->raw_response  = '';

		// SSE headers and output buffering are already handled by the REST
		// handler (prepare_sse_headers). Do NOT set headers or clean buffers
		// here — the stream is already open and events may have been sent.

		// Prepend the system prompt as the first message (OpenAI-compatible format).
		$full_messages = array_merge(
			array(
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				),
			),
			$messages
		);

		$request_body = array(
			'model'      => $model,
			'max_tokens' => $max_tokens,
			'stream'     => true,
			'messages'   => $full_messages,
		);

		if ( '' !== $request_tag ) {
			$request_body['metadata'] = array(
				'tags' => array( $request_tag ),
			);
		}

		$payload = wp_json_encode( $request_body );

		if ( false === $payload ) {
			$this->send_sse_event( 'error', error: 'Failed to encode request payload.' );
			return;
		}

		$endpoint = $this->get_endpoint();
		$headers  = array(
			'Content-Type: application/json',
			'Authorization: Bearer ' . $api_key,
		);

		$ch = curl_init();

		if ( false === $ch ) {
			$this->send_sse_event( 'error', error: 'Failed to initialize cURL.' );
			return;
		}

		curl_setopt_array( $ch, array(
			CURLOPT_URL            => $endpoint,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $payload,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
			CURLOPT_WRITEFUNCTION  => array( $this, 'handle_stream_chunk' ),
			CURLOPT_SSL_VERIFYPEER => true,
		) );

		$result = curl_exec( $ch );

		if ( false === $result ) {
			$curl_error = curl_error( $ch );
			$curl_errno = curl_errno( $ch );
			curl_close( $ch );

			VB_Logger::instance()->error(
				"LiteLLM cURL error ({$curl_errno}): {$curl_error}"
			);
			$this->send_sse_event( 'error', error: 'Connection to AI service failed. Please try again.' );
			return;
		}

		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		// Non-streamed error responses may arrive as plain JSON.
		if ( $http_code >= 400 ) {
			$this->handle_http_error( $http_code );
		}
	}

	/**
	 * cURL WRITEFUNCTION callback that receives raw SSE data chunks.
	 *
	 * LiteLLM uses the OpenAI-compatible streaming format:
	 *   data: {"id":"...","choices":[{"delta":{"content":"text"}}]}\n\n
	 *   data: [DONE]\n\n
	 *
	 * @param \CurlHandle $ch   The cURL handle.
	 * @param string      $data Raw chunk of SSE data.
	 * @return int Number of bytes handled (must match strlen($data) or cURL aborts).
	 */
	public function handle_stream_chunk( \CurlHandle $ch, string $data ): int {
		$bytes = strlen( $data );

		$this->raw_response .= $data;
		$this->buffer       .= $data;

		// Process all complete lines in the buffer.
		while ( str_contains( $this->buffer, "\n" ) ) {
			$newline_pos  = strpos( $this->buffer, "\n" );
			$line         = substr( $this->buffer, 0, $newline_pos );
			$this->buffer = substr( $this->buffer, $newline_pos + 1 );

			$line = trim( $line, "\r" );

			$this->process_sse_line( $line );
		}

		return $bytes;
	}

	/**
	 * Process a single SSE line from the LiteLLM stream.
	 *
	 * @param string $line A single line from the SSE stream.
	 */
	private function process_sse_line( string $line ): void {
		// Empty lines are event separators -- skip.
		if ( '' === $line ) {
			return;
		}

		// LiteLLM (OpenAI-compatible) only uses data: lines.
		if ( ! str_starts_with( $line, 'data:' ) ) {
			return;
		}

		$data_str = trim( substr( $line, 5 ) );

		// The stream termination signal.
		// Do NOT send 'done' here — the REST API handler sends it
		// after the theme generation pipeline completes.
		if ( '[DONE]' === $data_str ) {
			return;
		}

		$payload = json_decode( $data_str, true );

		if ( null === $payload ) {
			return;
		}

		// Check for API-level error in streamed response.
		if ( isset( $payload['error'] ) ) {
			$error_message = $payload['error']['message'] ?? 'Unknown LiteLLM proxy error.';
			VB_Logger::instance()->error( "LiteLLM stream error: {$error_message}" );
			$this->send_sse_event( 'error', error: 'AI service error. Please try again or select a different model.' );
			return;
		}

		// Extract the text delta from the choices array.
		$delta   = $payload['choices'][0]['delta'] ?? array();
		$content = $delta['content'] ?? null;

		if ( null !== $content && '' !== $content ) {
			$this->response_text .= $content;
			$this->send_sse_event( 'text_delta', content: $content );
		}
	}

	/**
	 * Get the accumulated full response text after streaming completes.
	 *
	 * @return string The full text content from all text_delta events.
	 */
	public function get_response_text(): string {
		return $this->response_text;
	}

	/**
	 * Make a non-streaming chat completion request to the LiteLLM proxy.
	 *
	 * Returns the full response text as a string. Used for secondary AI calls
	 * (e.g. security scanning) where streaming to the client is not needed.
	 *
	 * @param array  $messages      Array of message objects with 'role' and 'content' keys.
	 * @param string $model         LiteLLM model alias.
	 * @param string $system_prompt System prompt prepended as a system message.
	 * @param string $api_key       WPVibe service key.
	 * @param int    $max_tokens    Maximum tokens in the response.
	 * @return string The full response text.
	 *
	 * @throws \RuntimeException If the request fails or returns an error.
	 */
	public function complete(
		array $messages,
		string $model,
		string $system_prompt,
		string $api_key,
		int $max_tokens = 8192,
		string $request_tag = '',
	): string {
		$full_messages = array_merge(
			array( array( 'role' => 'system', 'content' => $system_prompt ) ),
			$messages
		);

		$request_body = array(
			'model'      => $model,
			'max_tokens' => $max_tokens,
			'stream'     => false,
			'messages'   => $full_messages,
		);

		if ( '' !== $request_tag ) {
			$request_body['metadata'] = array(
				'tags' => array( $request_tag ),
			);
		}

		$payload = wp_json_encode( $request_body );

		if ( false === $payload ) {
			throw new \RuntimeException( 'Failed to encode request payload.' );
		}

		$endpoint = $this->get_endpoint();

		$ch = curl_init();

		if ( false === $ch ) {
			throw new \RuntimeException( 'Failed to initialize cURL.' );
		}

		curl_setopt_array( $ch, array(
			CURLOPT_URL            => $endpoint,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $payload,
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'Authorization: Bearer ' . $api_key,
			),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_SSL_VERIFYPEER => true,
		) );

		$response  = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		if ( false === $response ) {
			$curl_error = curl_error( $ch );
			curl_close( $ch );
			throw new \RuntimeException( "LiteLLM cURL error: {$curl_error}" );
		}

		curl_close( $ch );

		if ( $http_code >= 400 ) {
			$error_data = json_decode( $response, true );
			$error_msg  = $error_data['error']['message']
				?? $error_data['detail']
				?? "HTTP {$http_code}";
			VB_Logger::instance()->error( "LiteLLM complete() error: {$error_msg}" );
			throw new \RuntimeException( "LiteLLM proxy error: {$error_msg}" );
		}

		$data = json_decode( $response, true );
		$text = $data['choices'][0]['message']['content'] ?? null;

		if ( null === $text ) {
			throw new \RuntimeException( 'LiteLLM returned no text content.' );
		}

		return $text;
	}

	/**
	 * Handle a non-streamed HTTP error response from the LiteLLM proxy.
	 *
	 * When the proxy returns an error status code, the response body may be
	 * a plain JSON object (not SSE). This method attempts to extract and
	 * forward the error message.
	 *
	 * @param int $http_code The HTTP status code.
	 */
	private function handle_http_error( int $http_code ): void {
		$raw = trim( $this->raw_response );

		if ( '' !== $raw ) {
			$error_body = json_decode( $raw, true );

			// LiteLLM may return errors in OpenAI format or its own format.
			$error_message = $error_body['error']['message']
				?? $error_body['detail']
				?? null;

			if ( null !== $error_message ) {
				VB_Logger::instance()->error( "LiteLLM API error (HTTP {$http_code}): {$error_message}" );

				// Detect credit/quota issues from the upstream provider.
				if ( str_contains( $error_message, 'quota' ) || str_contains( $error_message, 'credit' ) || str_contains( $error_message, 'billing' ) ) {
					$this->send_sse_event( 'error', error: "Insufficient credits. {$error_message}" );
				} else {
					$this->send_sse_event( 'error', error: $error_message );
				}
				return;
			}
		}

		VB_Logger::instance()->error( "LiteLLM proxy returned HTTP {$http_code}." );
		$this->send_sse_event(
			'error',
			error: "WP Vibe proxy error (HTTP {$http_code}). Check your service key and try again."
		);
	}

	/**
	 * Send a normalized SSE event to the client.
	 *
	 * All WPVibe provider clients emit events in the same format:
	 *   data: {"type": "<type>", "content": "...", "error": "..."}\n\n
	 *
	 * @param string      $type    Event type: 'text_delta', 'done', or 'error'.
	 * @param string|null $content Text content for text_delta events.
	 * @param string|null $error   Error message for error events.
	 */
	private function send_sse_event( string $type, ?string $content = null, ?string $error = null ): void {
		$event = array( 'type' => $type );

		if ( null !== $content ) {
			$event['content'] = $content;
		}

		if ( null !== $error ) {
			$event['error'] = $error;
		}

		echo 'data: ' . wp_json_encode( $event ) . "\n\n";
		flush();
	}
}
