<?php
/**
 * Tests for VB_Theme_Parser.
 *
 * @package WPVibe
 */

use PHPUnit\Framework\TestCase;

class ThemeParserTest extends TestCase {

	private VB_Theme_Parser $parser;

	protected function setUp(): void {
		$this->parser = new VB_Theme_Parser();
	}

	public function test_parses_valid_json_response(): void {
		$response = json_encode( [
			'message'         => 'Created a basic theme.',
			'files'           => [
				[ 'path' => 'style.css', 'content' => '/* Theme */' ],
				[ 'path' => 'index.php', 'content' => '<?php echo "hello";' ],
			],
			'preview_html'    => '<html><body>Preview</body></html>',
			'changes_summary' => [ 'Added style.css', 'Added index.php' ],
		] );

		$result = $this->parser->parse( $response );

		$this->assertNotNull( $result );
		$this->assertEquals( 'Created a basic theme.', $result['message'] );
		$this->assertCount( 2, $result['files'] );
		$this->assertEquals( 'style.css', $result['files'][0]['path'] );
		$this->assertStringContainsString( '<html>', $result['preview_html'] );
		$this->assertCount( 2, $result['changes_summary'] );
	}

	public function test_parses_json_in_code_fence(): void {
		$response = "Here's the theme:\n```json\n" . json_encode( [
			'message' => 'Theme with fence.',
			'files'   => [ [ 'path' => 'style.css', 'content' => '/* css */' ] ],
		] ) . "\n```\nDone!";

		$result = $this->parser->parse( $response );

		$this->assertNotNull( $result );
		$this->assertEquals( 'Theme with fence.', $result['message'] );
		$this->assertCount( 1, $result['files'] );
	}

	public function test_returns_null_for_invalid_json(): void {
		$result = $this->parser->parse( 'This is not JSON at all.' );
		$this->assertNull( $result );
	}

	public function test_returns_null_for_empty_string(): void {
		$result = $this->parser->parse( '' );
		$this->assertNull( $result );
	}

	public function test_returns_null_for_missing_files_key(): void {
		$response = json_encode( [ 'message' => 'No files here' ] );
		$result   = $this->parser->parse( $response );
		$this->assertNull( $result );
	}

	public function test_handles_oversized_response(): void {
		// Generate a response > 500KB
		$big = str_repeat( 'x', 600 * 1024 );
		$result = $this->parser->parse( $big );
		$this->assertNull( $result );
	}
}
