<?php
/**
 * OpenAI API client with SSE streaming support.
 *
 * Handles streaming from both the Chat Completions API and the
 * Responses API, normalizing the SSE output to WPVibe's unified format.
 *
 * @package WPVibe
 */

defined( 'ABSPATH' ) || exit;

class VB_OpenAI {

	/**
	 * OpenAI Chat Completions API endpoint.
	 */
	private const CHAT_ENDPOINT = 'https://api.openai.com/v1/chat/completions';

	/**
	 * OpenAI Responses API endpoint.
	 */
	private const RESPONSES_ENDPOINT = 'https://api.openai.com/v1/responses';

	/**
	 * Maximum cURL timeout in seconds.
	 */
	private const CURL_TIMEOUT = 300;

	/**
	 * Models that require the Responses API instead of Chat Completions.
	 * These models return 404 on /v1/chat/completions.
	 */
	private const RESPONSES_ONLY_MODELS = array(
		'gpt-5.3-codex',
		'gpt-5-codex',
		'gpt-5-pro',
		'gpt-5.1-codex-max',
	);

	/**
	 * Buffer for accumulating partial SSE lines from cURL chunks.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Accumulated full response text from text_delta events.
	 *
	 * @var string
	 */
	private string $response_text = '';

	/**
	 * Which SSE parsing mode is active: 'chat' or 'responses'.
	 *
	 * @var string
	 */
	private string $parse_mode = 'chat';

	/**
	 * Raw response data for error extraction after cURL completes.
	 *
	 * @var string
	 */
	private string $raw_response = '';

	/**
	 * Stream a response from the OpenAI API.
	 *
	 * Automatically selects between Chat Completions and Responses API
	 * based on the model.
	 *
	 * @param array  $messages      Array of message objects with 'role' and 'content' keys.
	 * @param string $model         OpenAI model ID.
	 * @param string $system_prompt System prompt.
	 * @param string $api_key       OpenAI API key.
	 * @param int    $max_tokens    Maximum tokens in the response.
	 */
	public function stream(
		array $messages,
		string $model,
		string $system_prompt,
		string $api_key,
		int $max_tokens = 16384,
	): void {
		if ( $this->is_responses_only_model( $model ) ) {
			$this->stream_responses_api( $messages, $model, $system_prompt, $api_key, $max_tokens );
		} else {
			$this->stream_chat_completions( $messages, $model, $system_prompt, $api_key, $max_tokens );
		}
	}

	/**
	 * Check if a model requires the Responses API.
	 */
	private function is_responses_only_model( string $model ): bool {
		return in_array( $model, self::RESPONSES_ONLY_MODELS, true );
	}

	/**
	 * Stream via the Chat Completions API (/v1/chat/completions).
	 *
	 * Used for: gpt-5.4, gpt-5.2, gpt-5.1, gpt-5.1-codex, gpt-5-mini, gpt-4o, etc.
	 */
	private function stream_chat_completions(
		array $messages,
		string $model,
		string $system_prompt,
		string $api_key,
		int $max_tokens,
	): void {
		$this->buffer        = '';
		$this->response_text = '';
		$this->raw_response  = '';
		$this->parse_mode    = 'chat';

		$full_messages = array_merge(
			array(
				array(
					'role'    => 'developer',
					'content' => $system_prompt,
				),
			),
			$messages
		);

		$request_body = array(
			'model'                 => $model,
			'max_completion_tokens' => $max_tokens,
			'stream'                => true,
			'messages'              => $full_messages,
		);

		// Reasoning models (codex, o-series) support reasoning_effort.
		if ( str_contains( $model, 'codex' ) || str_starts_with( $model, 'o' ) ) {
			$request_body['reasoning_effort'] = 'medium';
		}

		$this->curl_stream( self::CHAT_ENDPOINT, $request_body, $api_key );
	}

	/**
	 * Stream via the Responses API (/v1/responses).
	 *
	 * Used for: gpt-5.3-codex, gpt-5-codex, gpt-5-pro, gpt-5.1-codex-max.
	 * These models only work with the Responses API and return 404 on Chat Completions.
	 */
	private function stream_responses_api(
		array $messages,
		string $model,
		string $system_prompt,
		string $api_key,
		int $max_tokens,
	): void {
		$this->buffer        = '';
		$this->response_text = '';
		$this->raw_response  = '';
		$this->parse_mode    = 'responses';

		// Build the input array: previous messages as conversation context.
		// The Responses API uses different content types than Chat Completions:
		//   - 'image_url' → 'input_image'
		//   - 'text'      → 'input_text'
		$input = array();
		foreach ( $messages as $msg ) {
			$role = $msg['role'] ?? 'user';
			// Map 'system' role to 'developer' for Responses API.
			if ( 'system' === $role ) {
				$role = 'developer';
			}

			$content = $msg['content'] ?? '';

			// Convert multimodal content arrays to Responses API format.
			if ( is_array( $content ) ) {
				$converted = array();
				foreach ( $content as $block ) {
					$block_type = $block['type'] ?? '';
					if ( 'image_url' === $block_type ) {
						// Chat Completions format → Responses API format.
						$converted[] = array(
							'type'      => 'input_image',
							'image_url' => $block['image_url']['url'] ?? '',
						);
					} elseif ( 'text' === $block_type ) {
						$converted[] = array(
							'type' => 'input_text',
							'text' => $block['text'] ?? '',
						);
					} elseif ( 'image' === $block_type ) {
						// Anthropic format that may leak through — convert to Responses API.
						$source = $block['source'] ?? array();
						$converted[] = array(
							'type'      => 'input_image',
							'image_url' => 'data:' . ( $source['media_type'] ?? 'image/png' ) . ';base64,' . ( $source['data'] ?? '' ),
						);
					} else {
						$converted[] = $block;
					}
				}
				$content = $converted;
			}

			$input[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		$request_body = array(
			'model'             => $model,
			'instructions'      => $system_prompt,
			'input'             => $input,
			'max_output_tokens' => $max_tokens,
			'stream'            => true,
		);

		// Codex models support reasoning effort.
		if ( str_contains( $model, 'codex' ) ) {
			$request_body['reasoning'] = array( 'effort' => 'medium' );
		}

		$this->curl_stream( self::RESPONSES_ENDPOINT, $request_body, $api_key );
	}

	/**
	 * Open a streaming cURL connection and process the response.
	 *
	 * @param string $endpoint Full API URL.
	 * @param array  $body     Request body (will be JSON-encoded).
	 * @param string $api_key  OpenAI API key.
	 */
	private function curl_stream( string $endpoint, array $body, string $api_key ): void {
		$payload = wp_json_encode( $body );

		if ( false === $payload ) {
			$this->send_sse_event( 'error', error: 'Failed to encode request payload.' );
			return;
		}

		$headers = array(
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
				"OpenAI cURL error ({$curl_errno}): {$curl_error}"
			);
			$this->send_sse_event( 'error', error: "Connection error: {$curl_error}" );
			return;
		}

		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( $http_code >= 400 ) {
			$this->handle_http_error( $http_code );
		}
	}

	/**
	 * cURL WRITEFUNCTION callback that receives raw SSE data chunks.
	 *
	 * @param \CurlHandle $ch   The cURL handle.
	 * @param string      $data Raw chunk of SSE data.
	 * @return int Number of bytes handled (must match strlen($data) or cURL aborts).
	 */
	public function handle_stream_chunk( \CurlHandle $ch, string $data ): int {
		$bytes = strlen( $data );

		// Keep a copy of all raw data for error extraction after cURL completes.
		$this->raw_response .= $data;

		$this->buffer .= $data;

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
	 * Process a single SSE line, dispatching to the correct parser.
	 *
	 * @param string $line A single line from the SSE stream.
	 */
	private function process_sse_line( string $line ): void {
		if ( '' === $line ) {
			return;
		}

		// Responses API sends "event:" lines — skip them,
		// we determine event type from the JSON payload's "type" field.
		if ( str_starts_with( $line, 'event:' ) ) {
			return;
		}

		if ( ! str_starts_with( $line, 'data:' ) ) {
			return;
		}

		$data_str = trim( substr( $line, 5 ) );

		if ( '[DONE]' === $data_str ) {
			return;
		}

		$payload = json_decode( $data_str, true );

		if ( null === $payload ) {
			return;
		}

		// Check for API-level error in streamed response.
		if ( isset( $payload['error'] ) ) {
			$error_message = is_array( $payload['error'] )
				? ( $payload['error']['message'] ?? 'Unknown OpenAI API error.' )
				: (string) $payload['error'];
			VB_Logger::instance()->error( "OpenAI stream error: {$error_message}" );
			$this->send_sse_event( 'error', error: $error_message );
			return;
		}

		if ( 'responses' === $this->parse_mode ) {
			$this->process_responses_event( $payload );
		} else {
			$this->process_chat_event( $payload );
		}
	}

	/**
	 * Process a Chat Completions SSE event.
	 *
	 * Format: {"choices":[{"delta":{"content":"text"}}]}
	 */
	private function process_chat_event( array $payload ): void {
		$delta   = $payload['choices'][0]['delta'] ?? array();
		$content = $delta['content'] ?? null;

		if ( null !== $content && '' !== $content ) {
			$this->response_text .= $content;
			$this->send_sse_event( 'text_delta', content: $content );
		}
	}

	/**
	 * Process a Responses API SSE event.
	 *
	 * Key event types:
	 *   response.output_text.delta → {"type":"response.output_text.delta","delta":"text"}
	 *   response.completed         → stream finished
	 *   error                      → {"type":"error","message":"..."}
	 */
	private function process_responses_event( array $payload ): void {
		$type = $payload['type'] ?? '';

		if ( 'response.output_text.delta' === $type ) {
			$delta = $payload['delta'] ?? '';
			if ( '' !== $delta ) {
				$this->response_text .= $delta;
				$this->send_sse_event( 'text_delta', content: $delta );
			}
			return;
		}

		if ( 'error' === $type ) {
			$error_message = $payload['message'] ?? 'Unknown Responses API error.';
			VB_Logger::instance()->error( "OpenAI Responses API error: {$error_message}" );
			$this->send_sse_event( 'error', error: $error_message );
		}

		// response.completed, response.created, etc. — no action needed.
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
	 * Make a non-streaming chat completion request to the OpenAI API.
	 *
	 * Automatically selects between Chat Completions and Responses API
	 * based on the model. Returns the full response text as a string.
	 *
	 * @param array  $messages      Array of message objects with 'role' and 'content' keys.
	 * @param string $model         OpenAI model ID.
	 * @param string $system_prompt System prompt.
	 * @param string $api_key       OpenAI API key.
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
	): string {
		if ( $this->is_responses_only_model( $model ) ) {
			return $this->complete_responses_api( $messages, $model, $system_prompt, $api_key, $max_tokens );
		}

		return $this->complete_chat( $messages, $model, $system_prompt, $api_key, $max_tokens );
	}

	/**
	 * Non-streaming Chat Completions API call.
	 */
	private function complete_chat(
		array $messages,
		string $model,
		string $system_prompt,
		string $api_key,
		int $max_tokens,
	): string {
		$full_messages = array_merge(
			array( array( 'role' => 'developer', 'content' => $system_prompt ) ),
			$messages
		);

		$request_body = array(
			'model'                 => $model,
			'max_completion_tokens' => $max_tokens,
			'stream'                => false,
			'messages'              => $full_messages,
		);

		$response = $this->curl_complete( self::CHAT_ENDPOINT, $request_body, $api_key );
		$data     = json_decode( $response, true );
		$text     = $data['choices'][0]['message']['content'] ?? null;

		if ( null === $text ) {
			throw new \RuntimeException( 'OpenAI Chat returned no text content.' );
		}

		return $text;
	}

	/**
	 * Non-streaming Responses API call.
	 */
	private function complete_responses_api(
		array $messages,
		string $model,
		string $system_prompt,
		string $api_key,
		int $max_tokens,
	): string {
		$input = array();
		foreach ( $messages as $msg ) {
			$role = $msg['role'] ?? 'user';
			if ( 'system' === $role ) {
				$role = 'developer';
			}
			$input[] = array( 'role' => $role, 'content' => $msg['content'] ?? '' );
		}

		$request_body = array(
			'model'             => $model,
			'instructions'      => $system_prompt,
			'input'             => $input,
			'max_output_tokens' => $max_tokens,
		);

		$response = $this->curl_complete( self::RESPONSES_ENDPOINT, $request_body, $api_key );
		$data     = json_decode( $response, true );
		$text     = $data['output'][0]['content'][0]['text']
			?? $data['choices'][0]['message']['content']
			?? null;

		if ( null === $text ) {
			throw new \RuntimeException( 'OpenAI Responses API returned no text content.' );
		}

		return $text;
	}

	/**
	 * Run a non-streaming cURL request to an OpenAI endpoint.
	 *
	 * @return string Raw JSON response body.
	 * @throws \RuntimeException On cURL or HTTP errors.
	 */
	private function curl_complete( string $endpoint, array $body, string $api_key ): string {
		$payload = wp_json_encode( $body );

		if ( false === $payload ) {
			throw new \RuntimeException( 'Failed to encode request payload.' );
		}

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
			throw new \RuntimeException( "OpenAI cURL error: {$curl_error}" );
		}

		curl_close( $ch );

		if ( $http_code >= 400 ) {
			$error_data = json_decode( $response, true );
			$error_msg  = $error_data['error']['message'] ?? "HTTP {$http_code}";
			VB_Logger::instance()->error( "OpenAI complete() error: {$error_msg}" );
			throw new \RuntimeException( "OpenAI API error: {$error_msg}" );
		}

		return $response;
	}

	/**
	 * Handle a non-streamed HTTP error response from the OpenAI API.
	 *
	 * @param int $http_code The HTTP status code.
	 */
	private function handle_http_error( int $http_code ): void {
		// Use raw_response (complete data) since the buffer may have been
		// consumed by SSE line processing before we get here.
		$raw        = trim( $this->raw_response );
		$api_error  = '';
		$error_type = '';

		if ( '' !== $raw ) {
			$error_body = json_decode( $raw, true );
			if ( isset( $error_body['error']['message'] ) ) {
				$api_error = $error_body['error']['message'];
			} elseif ( isset( $error_body['error'] ) && is_string( $error_body['error'] ) ) {
				$api_error = $error_body['error'];
			}
			if ( isset( $error_body['error']['type'] ) ) {
				$error_type = $error_body['error']['type'];
			}
			VB_Logger::instance()->error( "OpenAI API error (HTTP {$http_code}): {$api_error} [{$error_type}]" );
		} else {
			VB_Logger::instance()->error( "OpenAI API returned HTTP {$http_code}." );
		}

		// Map known error types/codes to user-friendly messages.
		if ( 'insufficient_quota' === $error_type || str_contains( $api_error, 'quota' ) ) {
			$user_error = 'Your OpenAI account has insufficient credits. Please add billing credits at platform.openai.com/account/billing.';
		} elseif ( 'billing_hard_limit_reached' === $error_type || str_contains( $api_error, 'billing' ) ) {
			$user_error = 'Your OpenAI billing limit has been reached. Please increase your spending limit at platform.openai.com/account/billing.';
		} elseif ( str_contains( $api_error, 'exceeded' ) && str_contains( $api_error, 'rate' ) ) {
			$user_error = 'OpenAI rate limit exceeded. Please wait a moment and try again.';
		} elseif ( '' !== $api_error ) {
			$user_error = "OpenAI error: {$api_error}";
		} else {
			$safe_errors = array(
				401 => 'Authentication failed. Please check your OpenAI API key in Settings.',
				403 => 'Access denied. Your API key may not have permission for this model.',
				404 => 'Model not found. This model may not be available on your OpenAI account.',
				429 => 'Rate limit exceeded. Please wait a moment and try again.',
				500 => 'OpenAI API is experiencing issues. Please try again later.',
				503 => 'OpenAI API is temporarily unavailable. Please try again later.',
			);
			$user_error = $safe_errors[ $http_code ]
				?? "OpenAI API error (HTTP {$http_code}). Check your API key and model selection in Settings.";
		}

		$this->send_sse_event( 'error', error: $user_error );
	}

	/**
	 * Send a normalized SSE event to the client.
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
