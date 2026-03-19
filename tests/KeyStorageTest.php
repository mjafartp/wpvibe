<?php
/**
 * Tests for VB_Key_Storage encryption/decryption.
 *
 * @package WPVibe
 */

use PHPUnit\Framework\TestCase;

// Stub get_option / update_option for isolated testing.
$GLOBALS['_wp_options'] = [];

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return $GLOBALS['_wp_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value, $autoload = null ): bool {
		$GLOBALS['_wp_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $name ): bool {
		unset( $GLOBALS['_wp_options'][ $name ] );
		return true;
	}
}

class KeyStorageTest extends TestCase {

	private VB_Key_Storage $storage;

	protected function setUp(): void {
		$GLOBALS['_wp_options'] = [];
		$this->storage = new VB_Key_Storage();
	}

	public function test_encrypt_decrypt_roundtrip(): void {
		$original_key = 'sk-ant-test-key-1234567890abcdef';

		$this->storage->save_key( $original_key, 'claude_api' );

		$retrieved = $this->storage->get_key();
		$this->assertEquals( $original_key, $retrieved );
	}

	public function test_key_type_stored_correctly(): void {
		$this->storage->save_key( 'vb_live_test1234567890', 'wpvibe_service' );
		$this->assertEquals( 'wpvibe_service', $this->storage->get_key_type() );
	}

	public function test_has_key_returns_true_when_set(): void {
		$this->assertFalse( $this->storage->has_key() );

		$this->storage->save_key( 'sk-test123', 'openai_codex' );
		$this->assertTrue( $this->storage->has_key() );
	}

	public function test_different_keys_encrypt_differently(): void {
		$this->storage->save_key( 'key-one', 'claude_api' );
		$encrypted1 = get_option( 'wpvibe_api_key' );

		$this->storage->save_key( 'key-two', 'claude_api' );
		$encrypted2 = get_option( 'wpvibe_api_key' );

		$this->assertNotEquals( $encrypted1, $encrypted2 );
	}

	public function test_encrypted_value_is_not_plaintext(): void {
		$key = 'sk-ant-my-secret-key-12345';
		$this->storage->save_key( $key, 'claude_api' );

		$stored = get_option( 'wpvibe_api_key' );
		$this->assertNotEquals( $key, $stored );
		$this->assertStringNotContainsString( 'sk-ant', $stored );
	}
}
