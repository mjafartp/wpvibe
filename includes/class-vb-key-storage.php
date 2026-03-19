<?php
/**
 * Encrypted API key storage using AES-256-CBC.
 */

defined( 'ABSPATH' ) || exit;

class VB_Key_Storage {

	private string $encryption_key;

	public function __construct() {
		$this->encryption_key = hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY );
	}

	public function save_key( string $api_key, string $key_type ): void {
		$encrypted = $this->encrypt( $api_key );
		update_option( 'wpvibe_api_key', $encrypted, false );
		update_option( 'wpvibe_key_type', sanitize_text_field( $key_type ), false );
	}

	public function get_key(): string {
		$encrypted = get_option( 'wpvibe_api_key', '' );
		if ( empty( $encrypted ) ) {
			return '';
		}
		return $this->decrypt( $encrypted );
	}

	public function get_key_type(): string {
		return get_option( 'wpvibe_key_type', '' );
	}

	public function has_key(): bool {
		return ! empty( get_option( 'wpvibe_api_key', '' ) );
	}

	public function delete_key(): void {
		delete_option( 'wpvibe_api_key' );
		delete_option( 'wpvibe_key_type' );
	}

	/**
	 * Store OAuth data (access_token, refresh_token, expires_at).
	 */
	public function save_oauth_data( array $data ): void {
		$encrypted = $this->encrypt( wp_json_encode( $data ) );
		update_option( 'wpvibe_oauth_data', $encrypted, false );
	}

	public function get_oauth_data(): array {
		$encrypted = get_option( 'wpvibe_oauth_data', '' );
		if ( empty( $encrypted ) ) {
			return array();
		}
		$decrypted = $this->decrypt( $encrypted );
		$data      = json_decode( $decrypted, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Save an encrypted Figma Personal Access Token.
	 *
	 * @param string $token The Figma PAT to store.
	 */
	public function save_figma_token( string $token ): void {
		$encrypted = $this->encrypt( $token );
		update_option( 'wpvibe_figma_token', $encrypted, false );
	}

	/**
	 * Retrieve the decrypted Figma Personal Access Token.
	 *
	 * @return string The decrypted token, or empty string if not set.
	 */
	public function get_figma_token(): string {
		$encrypted = get_option( 'wpvibe_figma_token', '' );
		if ( '' === $encrypted ) {
			return '';
		}
		return $this->decrypt( $encrypted );
	}

	/**
	 * Check whether a Figma token has been configured.
	 *
	 * @return bool True if a Figma token is stored.
	 */
	public function has_figma_token(): bool {
		return '' !== get_option( 'wpvibe_figma_token', '' );
	}

	private function encrypt( string $data ): string {
		$iv        = random_bytes( 16 );
		$encrypted = openssl_encrypt( $data, 'AES-256-CBC', $this->encryption_key, OPENSSL_RAW_DATA, $iv );
		if ( false === $encrypted ) {
			return '';
		}
		return base64_encode( $iv . $encrypted );
	}

	private function decrypt( string $data ): string {
		$decoded = base64_decode( $data, true );
		if ( false === $decoded || strlen( $decoded ) < 17 ) {
			return '';
		}
		$iv        = substr( $decoded, 0, 16 );
		$encrypted = substr( $decoded, 16 );
		$decrypted = openssl_decrypt( $encrypted, 'AES-256-CBC', $this->encryption_key, OPENSSL_RAW_DATA, $iv );
		return false === $decrypted ? '' : $decrypted;
	}
}
