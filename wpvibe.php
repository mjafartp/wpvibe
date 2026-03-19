<?php
/**
 * Plugin Name:       WP Vibe — AI Theme Generator
 * Plugin URI:        https://wpvibe.net
 * Description:       Generate and customize WordPress themes using AI chat.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      8.1
 * Author:            WP Vibe
 * Author URI:        https://wpvibe.net
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpvibe
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

// PHP version check.
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'WP Vibe requires PHP 8.1 or higher. Please upgrade your PHP version.', 'wpvibe' );
		echo '</p></div>';
	} );
	return;
}

// WP version check.
if ( version_compare( get_bloginfo( 'version' ), '6.3', '<' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'WP Vibe requires WordPress 6.3 or higher. Please update WordPress.', 'wpvibe' );
		echo '</p></div>';
	} );
	return;
}

// Plugin constants.
define( 'WPVIBE_VERSION', '1.0.0' );
define( 'WPVIBE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPVIBE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPVIBE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoload classes.
require_once WPVIBE_PLUGIN_DIR . 'includes/class-vb-logger.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-vb-key-storage.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-vb-portal-client.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-vb-key-manager.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-vb-session-manager.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-vb-anthropic.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-vb-openai.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-vb-litellm.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-vb-ai-router.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-vb-rest-api.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-vb-theme-parser.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-vb-theme-writer.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-vb-theme-exporter.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-vb-preview-engine.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-vb-figma-client.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-vb-activator.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-vb-deactivator.php';
require_once WPVIBE_PLUGIN_DIR . 'admin/class-vb-admin.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe.php';

// Activation & deactivation hooks.
register_activation_hook( __FILE__, array( 'VB_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'VB_Deactivator', 'deactivate' ) );

// Boot the plugin.
$wpvibe = new WPVibe();
$wpvibe->run();
