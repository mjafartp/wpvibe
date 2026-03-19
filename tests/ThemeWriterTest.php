<?php
/**
 * Tests for VB_Theme_Writer.
 *
 * @package WPVibe
 */

use PHPUnit\Framework\TestCase;

class ThemeWriterTest extends TestCase {

	private VB_Theme_Writer $writer;
	private string $theme_root;
	private string $theme_slug = 'test-theme';

	protected function setUp(): void {
		$this->writer     = new VB_Theme_Writer();
		$this->theme_root = sys_get_temp_dir() . '/wpvibe-test-themes';

		// Clean up from previous runs.
		$theme_dir = $this->theme_root . '/' . $this->theme_slug;
		if ( is_dir( $theme_dir ) ) {
			$this->removeDir( $theme_dir );
		}
		wp_mkdir_p( $theme_dir );
	}

	protected function tearDown(): void {
		$theme_dir = $this->theme_root . '/' . $this->theme_slug;
		if ( is_dir( $theme_dir ) ) {
			$this->removeDir( $theme_dir );
		}
	}

	public function test_writes_allowed_file_types(): void {
		$files = [
			[ 'path' => 'style.css', 'content' => '/* Test CSS */' ],
			[ 'path' => 'index.php', 'content' => '<?php echo "test";' ],
			[ 'path' => 'theme.json', 'content' => '{"version": 2}' ],
		];

		$result = $this->writer->write_theme( $this->theme_slug, $files );

		$this->assertTrue( $result['success'] );
		$this->assertEmpty( $result['errors'] );

		$theme_dir = $this->theme_root . '/' . $this->theme_slug;
		$this->assertFileExists( $theme_dir . '/style.css' );
		$this->assertFileExists( $theme_dir . '/index.php' );
		$this->assertFileExists( $theme_dir . '/theme.json' );
		$this->assertEquals( '/* Test CSS */', file_get_contents( $theme_dir . '/style.css' ) );
	}

	public function test_rejects_disallowed_file_extensions(): void {
		$files = [
			[ 'path' => 'evil.exe', 'content' => 'malware' ],
			[ 'path' => 'hack.sh', 'content' => 'rm -rf /' ],
			[ 'path' => 'config.htaccess', 'content' => 'deny all' ],
		];

		$result = $this->writer->write_theme( $this->theme_slug, $files );

		$this->assertCount( 3, $result['errors'] );
	}

	public function test_rejects_path_traversal(): void {
		$files = [
			[ 'path' => '../../../etc/passwd', 'content' => 'root:x:0:0' ],
			[ 'path' => '..\\..\\windows\\system32\\test.php', 'content' => 'bad' ],
		];

		$result = $this->writer->write_theme( $this->theme_slug, $files );

		$this->assertCount( 2, $result['errors'] );
	}

	public function test_rejects_null_byte_in_path(): void {
		$files = [
			[ 'path' => "style\x00.css", 'content' => 'test' ],
		];

		$result = $this->writer->write_theme( $this->theme_slug, $files );

		$this->assertCount( 1, $result['errors'] );
	}

	public function test_creates_subdirectories(): void {
		$files = [
			[ 'path' => 'template-parts/header.php', 'content' => '<?php // header' ],
		];

		$result = $this->writer->write_theme( $this->theme_slug, $files );

		$this->assertTrue( $result['success'] );
		$theme_dir = $this->theme_root . '/' . $this->theme_slug;
		$this->assertFileExists( $theme_dir . '/template-parts/header.php' );
	}

	public function test_rejects_hidden_files(): void {
		$files = [
			[ 'path' => '.env', 'content' => 'SECRET=x' ],
			[ 'path' => '.gitignore', 'content' => 'node_modules' ],
		];

		$result = $this->writer->write_theme( $this->theme_slug, $files );

		$this->assertCount( 2, $result['errors'] );
	}

	private function removeDir( string $dir ): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $dir );
	}
}
