<?php
/**
 * ZIP archive creation for theme export and download.
 *
 * Packages generated theme files into a downloadable ZIP archive with proper
 * directory structure. Includes automatic cleanup of stale export files.
 *
 * @package WPVibe
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class VB_Theme_Exporter {

	/**
	 * Export theme files as a downloadable ZIP archive.
	 *
	 * Creates a ZIP file in the WordPress uploads directory containing all
	 * provided theme files nested under a directory named after the theme slug.
	 *
	 * @param array  $files      Array of file descriptors. Each element must
	 *                           contain 'path' (relative file path) and
	 *                           'content' (file contents as string).
	 * @param string $theme_slug Theme directory slug used as the root folder
	 *                           name inside the ZIP and in the filename.
	 *
	 * @return array {
	 *     Export result.
	 *
	 *     @type bool   $success Whether the export completed successfully.
	 *     @type string $url     Public URL to the ZIP file (empty on failure).
	 *     @type string $path    Absolute server path to the ZIP file (empty on failure).
	 *     @type string $error   Error message (empty on success).
	 * }
	 */
	public function export( array $files, string $theme_slug ): array {
		$result = array(
			'success' => false,
			'url'     => '',
			'path'    => '',
			'error'   => '',
		);

		// Ensure the ZipArchive extension is available.
		if ( ! class_exists( 'ZipArchive' ) ) {
			$result['error'] = __( 'The ZipArchive PHP extension is not available on this server.', 'wpvibe' );
			return $result;
		}

		// Validate that we have files to export.
		if ( empty( $files ) ) {
			$result['error'] = __( 'No theme files provided for export.', 'wpvibe' );
			return $result;
		}

		// Sanitize the theme slug.
		$theme_slug = sanitize_file_name( $theme_slug );
		if ( empty( $theme_slug ) ) {
			$result['error'] = __( 'Invalid theme slug.', 'wpvibe' );
			return $result;
		}

		// Build the export directory and file paths.
		$upload_dir = wp_upload_dir();
		$export_dir = $upload_dir['basedir'] . '/wpvibe/exports';
		$zip_name   = $theme_slug . '-' . time() . '.zip';
		$zip_path   = $export_dir . '/' . $zip_name;

		// Ensure the export directory exists.
		if ( ! file_exists( $export_dir ) ) {
			$created = wp_mkdir_p( $export_dir );
			if ( ! $created ) {
				$result['error'] = __( 'Failed to create the export directory.', 'wpvibe' );
				return $result;
			}
		}

		// Protect the export directory from direct browsing.
		$index_path = $export_dir . '/index.php';
		if ( ! file_exists( $index_path ) ) {
			file_put_contents( $index_path, "<?php\n// Silence is golden.\n" );
		}

		// Create the ZIP archive.
		$zip    = new ZipArchive();
		$opened = $zip->open( $zip_path, ZipArchive::CREATE );

		if ( true !== $opened ) {
			$result['error'] = sprintf(
				/* translators: %d: ZipArchive error code. */
				__( 'Failed to create ZIP archive (error code: %d).', 'wpvibe' ),
				$opened
			);
			return $result;
		}

		foreach ( $files as $file ) {
			// Each file must have a path and content key.
			if ( empty( $file['path'] ) || ! isset( $file['content'] ) ) {
				continue;
			}

			$relative_path = sanitize_text_field( $file['path'] );

			// Prevent directory traversal and unsafe paths.
			if (
				str_contains( $relative_path, '..' ) ||
				str_starts_with( $relative_path, '/' ) ||
				str_starts_with( $relative_path, '\\' ) ||
				preg_match( '/^[a-zA-Z]:/', $relative_path ) ||      // Windows absolute paths.
				str_contains( $relative_path, '\\\\' )               // UNC paths.
			) {
				continue;
			}

			// Normalize path separators and ensure the resolved path stays clean.
			$relative_path = str_replace( '\\', '/', $relative_path );
			$normalized    = array();
			foreach ( explode( '/', $relative_path ) as $segment ) {
				if ( '' === $segment || '.' === $segment ) {
					continue;
				}
				if ( '..' === $segment ) {
					continue; // Skip any traversal segment.
				}
				$normalized[] = $segment;
			}
			$relative_path = implode( '/', $normalized );

			if ( '' === $relative_path ) {
				continue;
			}

			$zip->addFromString( $theme_slug . '/' . $relative_path, $file['content'] );
		}

		$zip->close();

		// Verify the ZIP was actually written.
		if ( ! file_exists( $zip_path ) ) {
			$result['error'] = __( 'ZIP archive was not written to disk.', 'wpvibe' );
			return $result;
		}

		// Build the public URL.
		$zip_url = $upload_dir['baseurl'] . '/wpvibe/exports/' . $zip_name;

		$result['success'] = true;
		$result['url']     = $zip_url;
		$result['path']    = $zip_path;

		return $result;
	}

	/**
	 * Remove stale export ZIP files older than a given threshold.
	 *
	 * Scans the export directory for ZIP files and deletes any whose
	 * modification time is older than the specified number of hours.
	 *
	 * @param int $max_age_hours Maximum age in hours before a file is deleted.
	 *                           Defaults to 24 hours.
	 *
	 * @return int Number of files deleted.
	 */
	public function cleanup_exports( int $max_age_hours = 24 ): int {
		$upload_dir = wp_upload_dir();
		$export_dir = $upload_dir['basedir'] . '/wpvibe/exports';

		if ( ! is_dir( $export_dir ) ) {
			return 0;
		}

		$zip_files = glob( $export_dir . '/*.zip' );
		if ( ! is_array( $zip_files ) || empty( $zip_files ) ) {
			return 0;
		}

		$max_age_seconds = absint( $max_age_hours ) * HOUR_IN_SECONDS;
		$now             = time();
		$deleted_count   = 0;

		foreach ( $zip_files as $zip_file ) {
			$file_age = $now - filemtime( $zip_file );

			if ( $file_age > $max_age_seconds ) {
				if ( wp_delete_file( $zip_file ) || ! file_exists( $zip_file ) ) {
					++$deleted_count;
				}
			}
		}

		return $deleted_count;
	}
}
