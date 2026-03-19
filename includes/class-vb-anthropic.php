<?php
/**
 * Anthropic Claude API client with SSE streaming support.
 *
 * Handles both direct API key authentication (x-api-key header)
 * and OAuth bearer token authentication for Claude API calls.
 *
 * @package WPVibe
 */

defined( 'ABSPATH' ) || exit;

class VB_Anthropic {

	/**
	 * Anthropic Messages API endpoint.
	 */
	private const API_ENDPOINT = 'https://api.anthropic.com/v1/messages';

	/**
	 * Anthropic API version header value.
	 */
	private const API_VERSION = '2023-06-01';

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
	 * Tracks the current SSE event type while parsing multi-line events.
	 *
	 * @var string
	 */
	private string $current_event = '';

	/**
	 * Accumulated full response text from text_delta events.
	 *
	 * @var string
	 */
	private string $response_text = '';

	/**
	 * Raw response body accumulated for error diagnosis.
	 *
	 * @var string
	 */
	private string $raw_response = '';

	/**
	 * Whether an error was already sent to the client during streaming.
	 *
	 * @var bool
	 */
	private bool $error_sent = false;

	/**
	 * Current auth type for error context.
	 *
	 * @var string
	 */
	private string $auth_type = 'api_key';

	/**
	 * Stream a chat completion from the Anthropic Messages API.
	 *
	 * Opens a cURL connection with WRITEFUNCTION streaming and forwards
	 * parsed SSE events to the client in the normalized WPVibe format.
	 *
	 * @param array  $messages      Array of message objects with 'role' and 'content' keys.
	 * @param string $model         Anthropic model ID (e.g. 'claude-sonnet-4-5-20250514').
	 * @param string $system_prompt System prompt string sent outside the messages array.
	 * @param string $api_key       API key or OAuth token.
	 * @param string $auth_type     Authentication method: 'api_key' or 'oauth'.
	 * @param int    $max_tokens    Maximum tokens in the response.
	 */
	public function stream(
		array $messages,
		string $model,
		string $system_prompt,
		string $api_key,
		string $auth_type = 'api_key',
		int $max_tokens = 16384,
	): void {
		$this->buffer        = '';
		$this->current_event = '';
		$this->response_text = '';
		$this->raw_response  = '';
		$this->error_sent    = false;
		$this->auth_type     = $auth_type;

		// SSE headers and output buffering are already handled by the REST
		// handler (prepare_sse_headers). Do NOT set headers or clean buffers
		// here — the stream is already open and events may have been sent.

		$payload = wp_json_encode( array(
			'model'      => $model,
			'max_tokens' => $max_tokens,
			'stream'     => true,
			'system'     => $system_prompt,
			'messages'   => $messages,
		) );

		if ( false === $payload ) {
			$this->send_sse_event( 'error', error: 'Failed to encode request payload.' );
			return;
		}

		// Build request headers based on auth type.
		$headers = array(
			'Content-Type: application/json',
			'anthropic-version: ' . self::API_VERSION,
		);

		if ( 'oauth' === $auth_type ) {
			$headers[] = 'Authorization: Bearer ' . $api_key;
			$headers[] = 'anthropic-beta: oauth-2025-04-20';
		} else {
			$headers[] = 'x-api-key: ' . $api_key;
		}

		$ch = curl_init();

		if ( false === $ch ) {
			$this->send_sse_event( 'error', error: 'Failed to initialize cURL.' );
			return;
		}

		curl_setopt_array( $ch, array(
			CURLOPT_URL            => self::API_ENDPOINT,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $payload,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
			CURLOPT_WRITEFUNCTION  => array( $this, 'handle_stream_chunk' ),
			CURLOPT_SSL_VERIFYPEER => true,
		) );

		// Log request structure (without base64 data) for debugging.
		$debug_messages = array_map( static function ( $msg ) {
			$debug = array( 'role' => $msg['role'] );
			if ( is_array( $msg['content'] ?? null ) ) {
				$debug['content'] = array_map( static function ( $block ) {
					if ( 'image' === ( $block['type'] ?? '' ) ) {
						return array(
							'type'       => 'image',
							'media_type' => $block['source']['media_type'] ?? '?',
							'data_len'   => strlen( $block['source']['data'] ?? '' ),
						);
					}
					return $block;
				}, $msg['content'] );
			} else {
				$debug['content_len'] = strlen( $msg['content'] ?? '' );
			}
			return $debug;
		}, $messages );
		VB_Logger::instance()->debug(
			'Anthropic request: model=' . $model . ' messages=' . wp_json_encode( $debug_messages )
		);

		$result = curl_exec( $ch );

		if ( false === $result ) {
			$curl_error = curl_error( $ch );
			$curl_errno = curl_errno( $ch );
			curl_close( $ch );

			VB_Logger::instance()->error(
				"Anthropic cURL error ({$curl_errno}): {$curl_error}"
			);
			$this->send_sse_event( 'error', error: "Connection error: {$curl_error}" );
			return;
		}

		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		// If cURL succeeded but we got a non-2xx status, the error will have been
		// handled in handle_stream_chunk via the API's error event. However, if the
		// response was not streamed at all (e.g. immediate 401), send a fallback.
		if ( $http_code >= 400 && ! $this->error_sent ) {
			// Try to extract the actual error from the raw response body.
			$error_detail = '';
			$error_type   = '';
			$remaining    = trim( $this->raw_response );

			if ( '' !== $remaining ) {
				// Anthropic may return JSON error or SSE error event.
				// Try JSON first.
				$error_json = json_decode( $remaining, true );
				if ( is_array( $error_json ) ) {
					$error_detail = $error_json['error']['message']
						?? $error_json['message']
						?? '';
					$error_type = $error_json['error']['type'] ?? '';
				}
				// If not JSON, try to extract from SSE "data:" lines.
				if ( '' === $error_detail && str_contains( $remaining, 'data:' ) ) {
					if ( preg_match( '/data:\s*(\{.+\})/s', $remaining, $m ) ) {
						$sse_json = json_decode( $m[1], true );
						if ( is_array( $sse_json ) ) {
							$error_detail = $sse_json['error']['message']
								?? $sse_json['message']
								?? '';
							$error_type = $sse_json['error']['type'] ?? '';
						}
					}
				}
				if ( '' === $error_detail ) {
					// Last resort — show a trimmed snippet.
					$error_detail = mb_substr( $remaining, 0, 300 );
				}
			}

			VB_Logger::instance()->error(
				"Anthropic API returned HTTP {$http_code}. Detail: {$error_detail}"
			);

			// Detect the vague "Error" response that Anthropic returns for
			// OAuth token issues (expired, wrong scope, etc.).
			$is_oauth = 'oauth' === $this->auth_type;
			$is_vague = ( 'Error' === $error_detail || '' === $error_detail );

			if ( $is_oauth && $is_vague && 400 === $http_code ) {
				$user_error = 'Your Claude OAuth token appears to be expired or invalid. Please re-authenticate: go to Settings and reconnect your Claude account.';
			} elseif ( '' !== $error_detail && ! $is_vague ) {
				// Show the actual API error when it's useful.
				if ( str_contains( $error_detail, 'credit' ) || str_contains( $error_detail, 'billing' ) || str_contains( $error_detail, 'quota' ) ) {
					$user_error = "Insufficient credits on your Anthropic account. {$error_detail}";
				} else {
					$user_error = "Anthropic error: {$error_detail}";
				}
			} else {
				$safe_errors = array(
					401 => 'Authentication failed. Please check your Anthropic API key in Settings.',
					403 => 'Access denied. Your API key may not have permission for this model.',
					429 => 'Rate limit exceeded. Please wait a moment and try again.',
					500 => 'Anthropic API is experiencing issues. Please try again later.',
					529 => 'Anthropic API is overloaded. Please try again later.',
				);
				$user_error = $safe_errors[ $http_code ]
					?? "Anthropic API error (HTTP {$http_code}). Check your API key and model selection in Settings.";
			}

			$this->send_sse_event( 'error', error: $user_error );
		}
	}

	/**
	 * cURL WRITEFUNCTION callback that receives raw SSE data chunks.
	 *
	 * Anthropic streams events in the standard SSE format:
	 *   event: <type>\n
	 *   data: <json>\n\n
	 *
	 * This callback accumulates partial lines in a buffer and processes
	 * complete lines as they arrive.
	 *
	 * @param \CurlHandle $ch   The cURL handle.
	 * @param string      $data Raw chunk of SSE data.
	 * @return int Number of bytes handled (must match strlen($data) or cURL aborts).
	 */
	public function handle_stream_chunk( \CurlHandle $ch, string $data ): int {
		$bytes = strlen( $data );

		// Keep first 8KB of raw response for error diagnosis.
		if ( strlen( $this->raw_response ) < 8192 ) {
			$this->raw_response .= substr( $data, 0, 8192 - strlen( $this->raw_response ) );
		}

		$this->buffer .= $data;

		// Process all complete lines in the buffer.
		while ( str_contains( $this->buffer, "\n" ) ) {
			$newline_pos = strpos( $this->buffer, "\n" );
			$line        = substr( $this->buffer, 0, $newline_pos );
			$this->buffer = substr( $this->buffer, $newline_pos + 1 );

			$line = trim( $line, "\r" );

			$this->process_sse_line( $line );
		}

		return $bytes;
	}

	/**
	 * Process a single SSE line from the Anthropic stream.
	 *
	 * @param string $line A single line from the SSE stream.
	 */
	private function process_sse_line( string $line ): void {
		// Empty line signals end of an event block (already handled inline).
		if ( '' === $line ) {
			$this->current_event = '';
			return;
		}

		// Track the event type.
		if ( str_starts_with( $line, 'event:' ) ) {
			$this->current_event = trim( substr( $line, 6 ) );
			return;
		}

		// Process data lines.
		if ( str_starts_with( $line, 'data:' ) ) {
			$json_str = trim( substr( $line, 5 ) );

			if ( '' === $json_str ) {
				return;
			}

			$payload = json_decode( $json_str, true );

			if ( null === $payload ) {
				return;
			}

			$this->handle_event( $this->current_event, $payload );
		}
	}

	/**
	 * Handle a parsed Anthropic SSE event and forward it to the client.
	 *
	 * Anthropic event types:
	 * - message_start        : Contains message metadata.
	 * - content_block_start  : A new content block begins.
	 * - content_block_delta  : Incremental text content.
	 * - content_block_stop   : A content block ends.
	 * - message_delta        : Message-level updates (stop_reason, usage).
	 * - message_stop         : The message is complete.
	 * - error                : An error occurred.
	 * - ping                 : Keep-alive, ignored.
	 *
	 * @param string $event_type The SSE event type.
	 * @param array  $payload    The decoded JSON data payload.
	 */
	private function handle_event( string $event_type, array $payload ): void {
		switch ( $event_type ) {
			case 'content_block_delta':
				$text = $payload['delta']['text'] ?? null;
				if ( null !== $text ) {
					$this->response_text .= $text;
					$this->send_sse_event( 'text_delta', content: $text );
				}
				break;

			case 'message_stop':
				// Do NOT send 'done' here — the REST API handler sends it
				// after the theme generation pipeline completes, so the
				// frontend keeps reading the stream for theme_update events.
				break;

			case 'error':
				// Anthropic error payload: {"type":"error","error":{"type":"...","message":"..."}}
				$error_message = '';
				if ( isset( $payload['error'] ) && is_array( $payload['error'] ) ) {
					$error_message = $payload['error']['message'] ?? '';
				}
				if ( '' === $error_message ) {
					$error_message = $payload['message'] ?? '';
				}
				if ( '' === $error_message ) {
					$error_message = wp_json_encode( $payload );
				}
				VB_Logger::instance()->error( "Anthropic stream error: {$error_message}" );
				$this->send_sse_event( 'error', error: "Anthropic error: {$error_message}" );
				$this->error_sent = true;
				break;

			case 'message_start':
			case 'content_block_start':
			case 'content_block_stop':
			case 'message_delta':
			case 'ping':
				// Informational events -- no action needed on the client.
				break;

			default:
				// Unknown event types are silently ignored.
				break;
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
	 * Make a non-streaming chat completion request to the Anthropic Messages API.
	 *
	 * Returns the full response text as a string. Used for secondary AI calls
	 * (e.g. security scanning) where streaming to the client is not needed.
	 *
	 * @param array  $messages      Array of message objects with 'role' and 'content' keys.
	 * @param string $model         Anthropic model ID.
	 * @param string $system_prompt System prompt string.
	 * @param string $api_key       API key or OAuth token.
	 * @param string $auth_type     Authentication method: 'api_key' or 'oauth'.
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
		string $auth_type = 'api_key',
		int $max_tokens = 8192,
	): string {
		$payload = wp_json_encode( array(
			'model'      => $model,
			'max_tokens' => $max_tokens,
			'stream'     => false,
			'system'     => $system_prompt,
			'messages'   => $messages,
		) );

		if ( false === $payload ) {
			throw new \RuntimeException( 'Failed to encode request payload.' );
		}

		$headers = array(
			'Content-Type: application/json',
			'anthropic-version: ' . self::API_VERSION,
		);

		if ( 'oauth' === $auth_type ) {
			$headers[] = 'Authorization: Bearer ' . $api_key;
			$headers[] = 'anthropic-beta: oauth-2025-04-20';
		} else {
			$headers[] = 'x-api-key: ' . $api_key;
		}

		$ch = curl_init();

		if ( false === $ch ) {
			throw new \RuntimeException( 'Failed to initialize cURL.' );
		}

		curl_setopt_array( $ch, array(
			CURLOPT_URL            => self::API_ENDPOINT,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $payload,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_SSL_VERIFYPEER => true,
		) );

		$response  = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		if ( false === $response ) {
			$curl_error = curl_error( $ch );
			curl_close( $ch );
			throw new \RuntimeException( "Anthropic cURL error: {$curl_error}" );
		}

		curl_close( $ch );

		if ( $http_code >= 400 ) {
			$error_data = json_decode( $response, true );
			$error_msg  = $error_data['error']['message'] ?? "HTTP {$http_code}";
			VB_Logger::instance()->error( "Anthropic complete() error: {$error_msg}" );
			throw new \RuntimeException( "Anthropic API error: {$error_msg}" );
		}

		$data = json_decode( $response, true );
		$text = $data['content'][0]['text'] ?? null;

		if ( null === $text ) {
			throw new \RuntimeException( 'Anthropic returned no text content.' );
		}

		return $text;
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
