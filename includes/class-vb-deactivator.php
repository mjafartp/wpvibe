<?php
/**
 * Plugin deactivation handler.
 */

defined( 'ABSPATH' ) || exit;

class VB_Deactivator {

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'wpvibe_cleanup_images' );
	}
}
