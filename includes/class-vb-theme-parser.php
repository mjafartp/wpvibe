<?php
/**
 * Theme Parser — Parse AI responses into structured theme data.
 *
 * Extracts JSON from AI response text (which may be wrapped in markdown
 * code fences or embedded in conversational text) and normalizes it into
 * a structured array suitable for the theme writer.
 *
 * @package    WPVibe
 * @subpackage WPVibe/includes
 * @since      1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class VB_Theme_Parser
 *
 * Responsible for parsing AI-generated responses containing theme file data
 * in JSON format. Handles code-fenced JSON, raw JSON, truncated JSON,
 * and validates the expected structure before returning normalized output.
 *
 * @since 1.0.0
 */
class VB_Theme_Parser {

	/**
	 * Maximum allowed response size in bytes (2 MB).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MAX_RESPONSE_SIZE = 2097152;

	/**
	 * Parse an AI response string into structured theme data.
	 *
	 * Attempts to extract JSON from the response text, decode it, and
	 * validate that it conforms to the expected theme data structure.
	 * If the JSON is truncated (AI hit token limit), attempts to repair
	 * it by closing open brackets and extracting what files are complete.
	 *
	 * @since 1.0.0
	 *
	 * @param string $response_text The raw AI response text.
	 * @return array|null Normalized theme data array, or null on failure.
	 *                    Array shape: {
	 *                        message: string,
	 *                        files: array<array{path: string, content: string}>,
	 *                        preview_html: string,
	 *                        changes_summary: string[]
	 *                    }
	 */
	public function parse( string $response_text ): ?array {
		// Size guard.
		if ( strlen( $response_text ) > self::MAX_RESPONSE_SIZE ) {
			VB_Logger::instance()->info( 'Theme parser: response too large (' . strlen( $response_text ) . ' bytes)' );
			return null;
		}

		$response_text = trim( $response_text );

		if ( '' === $response_text ) {
			VB_Logger::instance()->info( 'Theme parser: empty response' );
			return null;
		}

		// Try standard extraction first (complete JSON).
		$json_string = $this->extract_json( $response_text );

		if ( null !== $json_string && $this->is_valid_json( $json_string ) ) {
			$data = json_decode( $json_string, true );

			if ( is_array( $data ) ) {
				$result = $this->validate_structure( $data );
				if ( null !== $result ) {
					return $result;
				}
				VB_Logger::instance()->info( 'Theme parser: validate_structure failed. Keys: ' . implode( ', ', array_keys( $data ) ) );
			}
		}

		// Standard extraction failed — attempt truncated JSON repair.
		VB_Logger::instance()->info( 'Theme parser: standard parse failed, attempting truncated JSON repair.' );
		return $this->parse_truncated( $response_text );
	}

	/**
	 * Attempt to parse a truncated JSON response.
	 *
	 * When the AI hits the token limit, the JSON is cut off mid-stream.
	 * This method tries to salvage usable data by:
	 *   1. Finding the "files" array and extracting complete file entries.
	 *   2. Extracting the "message" field if present.
	 *   3. Generating a minimal preview_html from the extracted files.
	 *
	 * @param string $text The raw (potentially truncated) response text.
	 * @return array|null Normalized theme data, or null if nothing salvageable.
	 */
	private function parse_truncated( string $text ): ?array {
		$start = strpos( $text, '{' );

		if ( false === $start ) {
			return null;
		}

		$json_text = substr( $text, $start );

		// Extract the "message" field.
		$message = '';
		if ( preg_match( '/"message"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $json_text, $m ) ) {
			$message = json_decode( '"' . $m[1] . '"' ) ?? '';
		}

		// Extract complete file entries from the "files" array.
		$files = $this->extract_complete_files( $json_text );

		if ( empty( $files ) ) {
			VB_Logger::instance()->info( 'Theme parser: truncated repair found no complete files.' );
			return null;
		}

		VB_Logger::instance()->info( 'Theme parser: truncated repair recovered ' . count( $files ) . ' files.' );

		// Extract preview_html if it's complete.
		$preview_html = '';
		if ( preg_match( '/"preview_html"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $json_text, $m ) ) {
			$preview_html = json_decode( '"' . $m[1] . '"' ) ?? '';
		}

		// If no preview_html, generate one from the extracted CSS/HTML files.
		if ( '' === $preview_html ) {
			$preview_html = $this->generate_preview_from_files( $files );
		}

		// Extract changes_summary if present.
		$changes_summary = array();
		if ( preg_match( '/"changes_summary"\s*:\s*\[((?:[^\[\]]|\[(?:[^\[\]])*\])*)\]/s', $json_text, $m ) ) {
			$items = json_decode( '[' . $m[1] . ']', true );
			if ( is_array( $items ) ) {
				$changes_summary = array_filter( $items, 'is_string' );
			}
		}

		return array(
			'message'         => $message,
			'files'           => $files,
			'preview_html'    => $preview_html,
			'changes_summary' => $changes_summary,
		);
	}

	/**
	 * Extract complete file entries from a potentially truncated JSON string.
	 *
	 * Scans for {"path":"...","content":"..."} objects within the "files" array.
	 * Only includes file entries where both path and content are complete
	 * (the closing quote and brace are found).
	 *
	 * @param string $json_text The raw JSON text (may be truncated).
	 * @return array Array of complete file entries with 'path' and 'content' keys.
	 */
	private function extract_complete_files( string $json_text ): array {
		$files = array();

		// Find the start of the "files" array.
		$files_pos = strpos( $json_text, '"files"' );

		if ( false === $files_pos ) {
			return array();
		}

		// Find the opening bracket of the files array.
		$bracket_pos = strpos( $json_text, '[', $files_pos );

		if ( false === $bracket_pos ) {
			return array();
		}

		// Scan for each file object within the array.
		$pos    = $bracket_pos + 1;
		$length = strlen( $json_text );

		while ( $pos < $length ) {
			// Find the next opening brace for a file object.
			$obj_start = strpos( $json_text, '{', $pos );

			if ( false === $obj_start ) {
				break;
			}

			// Check if we've gone past the end of the files array.
			$closing_bracket = strpos( $json_text, ']', $pos );
			if ( false !== $closing_bracket && $closing_bracket < $obj_start ) {
				break;
			}

			// Try to extract a complete JSON object using brace-matching.
			$obj_str = $this->extract_object_at( $json_text, $obj_start );

			if ( null === $obj_str ) {
				// Incomplete object — we're in the truncated part.
				break;
			}

			$file = json_decode( $obj_str, true );

			if (
				is_array( $file ) &&
				isset( $file['path'] ) && is_string( $file['path'] ) && '' !== trim( $file['path'] ) &&
				isset( $file['content'] ) && is_string( $file['content'] )
			) {
				$files[] = array(
					'path'    => trim( $file['path'] ),
					'content' => $file['content'],
				);
			}

			$pos = $obj_start + strlen( $obj_str );
		}

		return $files;
	}

	/**
	 * Extract a complete JSON object starting at the given position.
	 *
	 * Uses brace-matching with string/escape awareness.
	 *
	 * @param string $text  The text to search in.
	 * @param int    $start Position of the opening brace.
	 * @return string|null The complete JSON object string, or null if truncated.
	 */
	private function extract_object_at( string $text, int $start ): ?string {
		$length    = strlen( $text );
		$depth     = 0;
		$in_string = false;
		$escape    = false;

		for ( $i = $start; $i < $length; $i++ ) {
			$char = $text[ $i ];

			if ( $escape ) {
				$escape = false;
				continue;
			}

			if ( '\\' === $char && $in_string ) {
				$escape = true;
				continue;
			}

			if ( '"' === $char ) {
				$in_string = ! $in_string;
				continue;
			}

			if ( $in_string ) {
				continue;
			}

			if ( '{' === $char ) {
				$depth++;
			} elseif ( '}' === $char ) {
				$depth--;

				if ( 0 === $depth ) {
					return substr( $text, $start, $i - $start + 1 );
				}
			}
		}

		return null; // Truncated — closing brace not found.
	}

	/**
	 * Generate a preview HTML from extracted theme files.
	 *
	 * Combines header.php + index.php + footer.php (and other template
	 * parts) into a self-contained HTML page with inline CSS, stripping
	 * all PHP logic so the result is pure HTML for iframe preview.
	 *
	 * @param array $files Array of file entries with 'path' and 'content'.
	 * @return string A self-contained HTML preview page.
	 */
	public function generate_preview_from_files( array $files ): string {
		// Index files by path for quick lookup.
		$file_map = array();
		foreach ( $files as $file ) {
			$file_map[ $file['path'] ] = $file['content'] ?? '';
		}

		$css    = $file_map['style.css'] ?? '';
		$header = $file_map['header.php'] ?? '';
		$index  = $file_map['index.php'] ?? '';
		$footer = $file_map['footer.php'] ?? '';

		// If there's nothing to work with, bail.
		if ( '' === $css && '' === $header && '' === $index && '' === $footer ) {
			return '';
		}

		// Strip PHP from each template part.
		$header_html = $this->strip_php( $header );
		$index_html  = $this->strip_php( $index );
		$footer_html = $this->strip_php( $footer );

		// The header usually contains <!DOCTYPE>, <html>, <head>, <body>.
		// The footer usually contains closing </body></html>.
		// If the header has a full HTML skeleton, inject our CSS into it.

		if ( '' !== $header_html && str_contains( $header_html, '</head>' ) ) {
			// Inject the theme CSS into the <head>.
			$combined = str_replace(
				'</head>',
				'<style>' . $css . '</style></head>',
				$header_html
			);
			$combined .= $index_html . $footer_html;

			return $combined;
		}

		// Fallback: header doesn't have a full HTML skeleton — build one.
		return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'
			. $css
			. '</style></head><body>'
			. $header_html
			. $index_html
			. $footer_html
			. '</body></html>';
	}

	/**
	 * Strip PHP code from a template file, leaving only HTML output.
	 *
	 * Removes <?php ... ?> blocks, standalone PHP function calls,
	 * and WordPress template tags that have no visual output.
	 *
	 * @param string $content Raw PHP template content.
	 * @return string HTML-only content.
	 */
	private function strip_php( string $content ): string {
		if ( '' === $content ) {
			return '';
		}

		// Remove full <?php ... ? > blocks (including multiline).
		$open_tag = '<' . '?php';
		$html = preg_replace( '/' . preg_quote( $open_tag, '/' ) . '\b.*?\?' . '>/s', '', $content );

		// Remove any remaining unclosed <?php blocks (end of file without ? >).
		$html = preg_replace( '/' . preg_quote( $open_tag, '/' ) . '\b.*$/s', '', $html );

		// Clean up residual whitespace from removed blocks.
		$html = preg_replace( '/^\s*\n/m', '', $html );

		return trim( $html );
	}

	/**
	 * Extract a JSON string from raw AI response text.
	 *
	 * Uses two strategies in order:
	 *   1. Look for JSON inside markdown code fences.
	 *   2. Brace-match from the first `{` to the closing `}`.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text The raw text to extract JSON from.
	 * @return string|null The extracted JSON string, or null if none found.
	 */
	private function extract_json( string $text ): ?string {
		// Strategy 1: Regex for markdown code fences.
		$pattern = '/```(?:json)?\s*\n([\s\S]*?)\n\s*```/';

		if ( preg_match( $pattern, $text, $matches ) ) {
			$candidate = trim( $matches[1] );

			if ( $this->is_valid_json( $candidate ) ) {
				return $candidate;
			}
		}

		// Strategy 2: Brace-matching from the first `{`.
		$start = strpos( $text, '{' );

		if ( false === $start ) {
			return null;
		}

		$obj_str = $this->extract_object_at( $text, $start );

		if ( null !== $obj_str ) {
			return $obj_str;
		}

		return null;
	}

	/**
	 * Validate and normalize the decoded JSON structure.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data The decoded JSON data.
	 * @return array|null Normalized data array, or null if `files` is invalid.
	 */
	private function validate_structure( array $data ): ?array {
		if ( ! isset( $data['files'] ) || ! is_array( $data['files'] ) || empty( $data['files'] ) ) {
			return null;
		}

		$valid_files = array();

		foreach ( $data['files'] as $file ) {
			if ( ! is_array( $file ) ) {
				continue; // Skip invalid entries instead of failing entirely.
			}

			if ( ! isset( $file['path'] ) || ! is_string( $file['path'] ) || '' === trim( $file['path'] ) ) {
				continue;
			}

			if ( ! isset( $file['content'] ) || ! is_string( $file['content'] ) ) {
				continue;
			}

			$valid_files[] = array(
				'path'    => trim( $file['path'] ),
				'content' => $file['content'],
			);
		}

		if ( empty( $valid_files ) ) {
			return null;
		}

		$message         = isset( $data['message'] ) && is_string( $data['message'] ) ? $data['message'] : '';
		$preview_html    = isset( $data['preview_html'] ) && is_string( $data['preview_html'] ) ? $data['preview_html'] : '';
		$changes_summary = array();

		if ( isset( $data['changes_summary'] ) && is_array( $data['changes_summary'] ) ) {
			foreach ( $data['changes_summary'] as $item ) {
				if ( is_string( $item ) ) {
					$changes_summary[] = $item;
				}
			}
		}

		// If preview_html is empty, generate from files.
		if ( '' === $preview_html ) {
			$preview_html = $this->generate_preview_from_files( $valid_files );
		}

		return array(
			'message'         => $message,
			'files'           => $valid_files,
			'preview_html'    => $preview_html,
			'changes_summary' => $changes_summary,
		);
	}

	/**
	 * Check whether a string is valid JSON.
	 *
	 * @since 1.0.0
	 *
	 * @param string $str The string to check.
	 * @return bool True if the string decodes to a non-null value.
	 */
	private function is_valid_json( string $str ): bool {
		if ( '' === $str ) {
			return false;
		}

		json_decode( $str );

		return ( JSON_ERROR_NONE === json_last_error() );
	}
}
