<?php
/**
 * Figma REST API client for WPVibe.
 *
 * Provides methods to interact with the Figma API using a Personal Access Token.
 * Used for fetching design frames, screenshots, and extracting design tokens.
 *
 * @package WPVibe
 */

defined( 'ABSPATH' ) || exit;

class VB_Figma_Client {

	private const API_BASE = 'https://api.figma.com/v1';

	/**
	 * Parse a Figma URL to extract the file key and optional node ID.
	 *
	 * Supports formats:
	 *   https://www.figma.com/file/ABC123/FileName
	 *   https://www.figma.com/file/ABC123/FileName?node-id=1-23
	 *   https://www.figma.com/design/ABC123/FileName?node-id=1:23
	 *
	 * @param string $url The Figma URL.
	 * @return array|null Array with 'file_key' and optional 'node_id', or null if invalid.
	 */
	public function parse_figma_url( string $url ): ?array {
		// Validate URL structure first.
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) || empty( $parsed['scheme'] ) ) {
			return null;
		}

		// Enforce HTTPS and exact Figma domain.
		$scheme = strtolower( $parsed['scheme'] );
		$host   = strtolower( $parsed['host'] );

		if ( 'https' !== $scheme ) {
			return null;
		}

		if ( 'www.figma.com' !== $host && 'figma.com' !== $host ) {
			return null;
		}

		// Extract file key from path.
		if ( ! preg_match( '#/(?:file|design)/([a-zA-Z0-9]+)#', $parsed['path'] ?? '', $matches ) ) {
			return null;
		}

		$result = array( 'file_key' => $matches[1] );

		if ( ! empty( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $query_params );
			if ( ! empty( $query_params['node-id'] ) ) {
				// Figma URLs use hyphens in node IDs, API uses colons.
				// Validate format: digits and hyphens/colons only.
				$node_id = $query_params['node-id'];
				if ( preg_match( '/^[0-9]+[-:][0-9]+$/', $node_id ) ) {
					$result['node_id'] = str_replace( '-', ':', $node_id );
				}
			}
		}

		return $result;
	}

	/**
	 * Verify the Figma token by fetching the current user.
	 *
	 * @param string $token Figma Personal Access Token.
	 * @return array|null User info array with 'handle' and 'email', or null on failure.
	 */
	public function get_current_user( string $token ): ?array {
		$response = $this->api_request( '/me', $token );
		if ( null === $response ) {
			return null;
		}

		return array(
			'handle' => $response['handle'] ?? '',
			'email'  => $response['email'] ?? '',
		);
	}

	/**
	 * Get file info with top-level frames.
	 *
	 * @param string $file_key The Figma file key.
	 * @param string $token    Figma PAT.
	 * @return array|null Array with 'name' and 'frames' list, or null on failure.
	 */
	public function get_file_info( string $file_key, string $token ): ?array {
		$response = $this->api_request( "/files/{$file_key}?depth=2", $token );
		if ( null === $response ) {
			return null;
		}

		$frames   = array();
		$document = $response['document'] ?? array();
		$children = $document['children'] ?? array();

		foreach ( $children as $page ) {
			if ( 'CANVAS' !== ( $page['type'] ?? '' ) ) {
				continue;
			}

			$page_name = $page['name'] ?? 'Untitled Page';

			foreach ( $page['children'] ?? array() as $node ) {
				$node_type = $node['type'] ?? '';
				if ( in_array( $node_type, array( 'FRAME', 'COMPONENT', 'COMPONENT_SET' ), true ) ) {
					$frames[] = array(
						'id'       => $node['id'],
						'name'     => $node['name'] ?? 'Untitled',
						'pageName' => $page_name,
						'type'     => $node_type,
					);
				}
			}
		}

		return array(
			'name'   => $response['name'] ?? 'Untitled File',
			'frames' => $frames,
		);
	}

	/**
	 * Get rendered PNG images for specific nodes.
	 *
	 * @param string   $file_key The Figma file key.
	 * @param string[] $node_ids Array of node IDs to render.
	 * @param string   $token    Figma PAT.
	 * @return array Map of node_id => base64-encoded PNG data.
	 */
	public function get_frame_images( string $file_key, array $node_ids, string $token ): array {
		$ids_param = implode( ',', array_map( 'rawurlencode', $node_ids ) );
		$response  = $this->api_request(
			"/images/{$file_key}?ids={$ids_param}&format=png&scale=2",
			$token
		);

		if ( null === $response || empty( $response['images'] ) ) {
			return array();
		}

		$result = array();
		foreach ( $response['images'] as $node_id => $image_url ) {
			if ( empty( $image_url ) ) {
				continue;
			}

			$image_response = wp_remote_get( $image_url, array( 'timeout' => 30 ) );
			if ( is_wp_error( $image_response ) ) {
				continue;
			}

			$image_body = wp_remote_retrieve_body( $image_response );
			if ( '' !== $image_body ) {
				$result[ $node_id ] = base64_encode( $image_body );
			}
		}

		return $result;
	}

	/**
	 * Extract design tokens from a Figma file's document tree.
	 *
	 * Best-effort extraction of colors, typography, and spacing from
	 * the node tree returned by the Figma API.
	 *
	 * @param array  $file_data Full file data from GET /v1/files.
	 * @param string $node_id   The node ID to extract tokens from.
	 * @return array Design tokens with 'colors', 'typography', 'spacing' keys.
	 */
	public function extract_design_tokens( array $file_data, string $node_id ): array {
		$tokens = array(
			'colors'     => array(),
			'typography' => array(),
			'spacing'    => array(),
		);

		$node = $this->find_node( $file_data['document'] ?? array(), $node_id );
		if ( null === $node ) {
			return $tokens;
		}

		$this->collect_tokens( $node, $tokens );

		$tokens['colors'] = array_values( array_unique( $tokens['colors'] ) );

		return $tokens;
	}

	/**
	 * Find a node in the Figma document tree by ID.
	 *
	 * @param array  $node      Current node to search.
	 * @param string $target_id The node ID to find.
	 * @return array|null The matching node, or null if not found.
	 */
	private function find_node( array $node, string $target_id ): ?array {
		if ( ( $node['id'] ?? '' ) === $target_id ) {
			return $node;
		}

		foreach ( $node['children'] ?? array() as $child ) {
			$found = $this->find_node( $child, $target_id );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Recursively collect design tokens from a node tree.
	 *
	 * @param array $node   The node to extract tokens from.
	 * @param array $tokens Accumulated tokens (passed by reference).
	 */
	private function collect_tokens( array $node, array &$tokens ): void {
		// Extract fill colors.
		foreach ( $node['fills'] ?? array() as $fill ) {
			if ( 'SOLID' === ( $fill['type'] ?? '' ) && isset( $fill['color'] ) ) {
				$c   = $fill['color'];
				$hex = sprintf(
					'#%02x%02x%02x',
					(int) round( ( $c['r'] ?? 0 ) * 255 ),
					(int) round( ( $c['g'] ?? 0 ) * 255 ),
					(int) round( ( $c['b'] ?? 0 ) * 255 )
				);
				$tokens['colors'][] = $hex;
			}
		}

		// Extract typography from text nodes.
		if ( 'TEXT' === ( $node['type'] ?? '' ) && isset( $node['style'] ) ) {
			$style = $node['style'];
			$tokens['typography'][] = array(
				'fontFamily' => $style['fontFamily'] ?? '',
				'fontWeight' => $style['fontWeight'] ?? 400,
				'fontSize'   => $style['fontSize'] ?? 16,
				'lineHeight' => $style['lineHeightPx'] ?? null,
			);
		}

		// Extract spacing from auto-layout frames.
		if ( isset( $node['layoutMode'] ) ) {
			$spacing = array();
			if ( isset( $node['itemSpacing'] ) ) {
				$spacing['gap'] = $node['itemSpacing'] . 'px';
			}
			if ( isset( $node['paddingTop'] ) ) {
				$spacing['padding'] = sprintf(
					'%dpx %dpx %dpx %dpx',
					$node['paddingTop'] ?? 0,
					$node['paddingRight'] ?? 0,
					$node['paddingBottom'] ?? 0,
					$node['paddingLeft'] ?? 0
				);
			}
			if ( ! empty( $spacing ) ) {
				$tokens['spacing'][] = $spacing;
			}
		}

		foreach ( $node['children'] ?? array() as $child ) {
			$this->collect_tokens( $child, $tokens );
		}
	}

	/**
	 * Make an authenticated GET request to the Figma API.
	 *
	 * @param string $path  API path relative to /v1 (e.g. '/files/ABC123').
	 * @param string $token Figma Personal Access Token.
	 * @return array|null Decoded JSON response, or null on failure.
	 */
	private function api_request( string $path, string $token ): ?array {
		$url = self::API_BASE . '/' . ltrim( $path, '/' );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'X-Figma-Token' => $token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			VB_Logger::instance()->error( 'Figma API error: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			VB_Logger::instance()->error( "Figma API returned HTTP {$code}." );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		return is_array( $data ) ? $data : null;
	}
}
