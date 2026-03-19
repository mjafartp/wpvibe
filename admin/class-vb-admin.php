<?php
/**
 * Admin page registration, menu, and script enqueue.
 */

defined( 'ABSPATH' ) || exit;

class VB_Admin {

	private string $plugin_name;
	private string $version;
	private VB_Key_Storage $key_storage;
	private VB_Key_Manager $key_manager;

	public function __construct( string $plugin_name, string $version, VB_Key_Storage $key_storage, VB_Key_Manager $key_manager ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->key_storage = $key_storage;
		$this->key_manager = $key_manager;
	}

	/**
	 * Register the admin menu and subpages.
	 */
	public function register_menu(): void {
		// Main menu page (Theme Editor).
		add_menu_page(
			__( 'WP Vibe', 'wpvibe' ),
			__( 'WP Vibe', 'wpvibe' ),
			'edit_themes',
			'wpvibe',
			array( $this, 'render_editor_page' ),
			'dashicons-art',
			30
		);

		// Submenus.
		add_submenu_page(
			'wpvibe',
			__( 'Theme Editor', 'wpvibe' ),
			__( 'Theme Editor', 'wpvibe' ),
			'edit_themes',
			'wpvibe',
			array( $this, 'render_editor_page' )
		);

		add_submenu_page(
			'wpvibe',
			__( 'Settings', 'wpvibe' ),
			__( 'Settings', 'wpvibe' ),
			'edit_themes',
			'wpvibe-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'wpvibe',
			__( 'Theme History', 'wpvibe' ),
			__( 'Theme History', 'wpvibe' ),
			'edit_themes',
			'wpvibe-history',
			array( $this, 'render_history_page' )
		);

		add_submenu_page(
			'wpvibe',
			__( 'Help & Docs', 'wpvibe' ),
			__( 'Help & Docs', 'wpvibe' ),
			'edit_themes',
			'wpvibe-help',
			array( $this, 'render_help_page' )
		);

		// Hidden onboarding page (not in menu).
		add_submenu_page(
			null, // Hidden.
			__( 'WP Vibe Setup', 'wpvibe' ),
			__( 'Setup', 'wpvibe' ),
			'edit_themes',
			'wpvibe-onboarding',
			array( $this, 'render_onboarding_page' )
		);
	}

	/**
	 * Enqueue scripts and styles on plugin pages only.
	 */
	public function enqueue_scripts( string $hook ): void {
		// Only load on our plugin pages.
		if ( ! $this->is_plugin_page( $hook ) ) {
			return;
		}

		// Admin CSS for all plugin pages.
		wp_enqueue_style(
			'wpvibe-admin',
			WPVIBE_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			$this->version
		);

		// Localized data for all plugin pages.
		$localized_data = array(
			'restUrl'            => esc_url_raw( rest_url( 'wpvibe/v1/' ) ),
			'nonce'              => wp_create_nonce( 'wp_rest' ),
			'hasKey'             => $this->key_storage->has_key(),
			'keyType'            => $this->key_storage->get_key_type(),
			'selectedModel'      => get_option( 'wpvibe_selected_model', '' ),
			'onboardingComplete' => (bool) get_option( 'wpvibe_onboarding_complete', false ),
			'adminUrl'           => admin_url(),
			'pluginUrl'          => WPVIBE_PLUGIN_URL,
			'hasFigma'           => $this->key_storage->has_figma_token(),
			'cssFramework'       => get_option( 'wpvibe_css_framework', 'tailwind' ),
		);

		// Editor page.
		if ( str_contains( $hook, 'wpvibe' ) && ! str_contains( $hook, 'settings' ) && ! str_contains( $hook, 'onboarding' ) ) {
			$this->enqueue_react_bundle( 'editor', $localized_data );
		}

		// Settings page.
		if ( str_contains( $hook, 'wpvibe-settings' ) ) {
			$this->enqueue_react_bundle( 'settings', $localized_data );
		}
	}

	/**
	 * Redirect to onboarding if setup is not complete.
	 */
	public function maybe_redirect_onboarding(): void {
		if ( ! current_user_can( 'edit_themes' ) ) {
			return;
		}

		// Only redirect from main plugin pages, not during AJAX or onboarding itself.
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$screen = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		if ( $screen === '' ) {
			return;
		}

		// Don't redirect if we're already on onboarding or settings.
		if ( in_array( $screen, array( 'wpvibe-onboarding', 'wpvibe-settings' ), true ) ) {
			return;
		}

		// Redirect to onboarding if not complete and visiting a plugin page.
		if ( str_starts_with( $screen, 'wpvibe' ) && ! get_option( 'wpvibe_onboarding_complete', false ) ) {
			if ( headers_sent() ) {
				echo '<script>window.location.href=' . wp_json_encode( admin_url( 'admin.php?page=wpvibe-onboarding' ) ) . ';</script>';
				exit;
			}
			wp_safe_redirect( admin_url( 'admin.php?page=wpvibe-onboarding' ) );
			exit;
		}
	}

	// --- Page renderers ---

	public function render_editor_page(): void {
		require_once WPVIBE_PLUGIN_DIR . 'admin/partials/editor-page.php';
	}

	public function render_settings_page(): void {
		require_once WPVIBE_PLUGIN_DIR . 'admin/partials/settings-page.php';
	}

	public function render_onboarding_page(): void {
		require_once WPVIBE_PLUGIN_DIR . 'admin/partials/onboarding-page.php';
	}

	public function render_history_page(): void {
		require_once WPVIBE_PLUGIN_DIR . 'admin/partials/history-page.php';
	}

	public function render_help_page(): void {
		require_once WPVIBE_PLUGIN_DIR . 'admin/partials/help-page.php';
	}

	// --- Helpers ---

	private function is_plugin_page( string $hook ): bool {
		return str_contains( $hook, 'wpvibe' );
	}

	private function enqueue_react_bundle( string $bundle_name, array $localized_data ): void {
		$js_file  = WPVIBE_PLUGIN_DIR . "dist/{$bundle_name}.js";
		$css_file = WPVIBE_PLUGIN_DIR . "dist/{$bundle_name}.css";

		// JS bundle — loaded as ES module (Vite output).
		if ( file_exists( $js_file ) ) {
			$handle = "wpvibe-{$bundle_name}";
			wp_enqueue_script(
				$handle,
				WPVIBE_PLUGIN_URL . "dist/{$bundle_name}.js",
				array(),
				(string) filemtime( $js_file ),
				true
			);

			// Add type="module" only to the main script tag (the one with src=),
			// not the inline "before" script that sets wpvibeData.
			add_filter( 'script_loader_tag', function ( string $tag, string $h ) use ( $handle ): string {
				if ( $h === $handle ) {
					$tag = preg_replace(
						'/<script\b([^>]*)\bsrc=/',
						'<script type="module"$1src=',
						$tag
					);
				}
				return $tag;
			}, 10, 2 );

			// Inject wpvibeData as a classic inline script before the module.
			// wp_localize_script can be unreliable with type="module" scripts.
			$json = wp_json_encode( $localized_data );
			wp_add_inline_script( $handle, "window.wpvibeData = {$json};", 'before' );
		}

		// CSS bundle.
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				"wpvibe-{$bundle_name}",
				WPVIBE_PLUGIN_URL . "dist/{$bundle_name}.css",
				array(),
				(string) filemtime( $css_file )
			);
		}
	}
}
