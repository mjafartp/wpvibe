<?php
/**
 * Main plugin class.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe {

	private string $plugin_name = 'wpvibe';
	private string $version;
	private VB_Key_Storage $key_storage;
	private VB_Key_Manager $key_manager;
	private VB_Session_Manager $session_manager;
	private VB_AI_Router $ai_router;
	private VB_Theme_Parser $theme_parser;
	private VB_Theme_Writer $theme_writer;
	private VB_Theme_Exporter $theme_exporter;
	private VB_Preview_Engine $preview_engine;

	public function __construct() {
		$this->version         = WPVIBE_VERSION;
		$this->key_storage     = new VB_Key_Storage();
		$this->key_manager     = new VB_Key_Manager( $this->key_storage );
		$this->session_manager = new VB_Session_Manager();
		$this->ai_router       = new VB_AI_Router( $this->key_storage );
		$this->theme_parser    = new VB_Theme_Parser();
		$this->theme_writer    = new VB_Theme_Writer();
		$this->theme_exporter  = new VB_Theme_Exporter();
		$this->preview_engine  = new VB_Preview_Engine();
	}

	public function run(): void {
		$this->define_admin_hooks();
		$this->define_api_hooks();

		// Register preview hooks early so theme-switch filters are in place
		// before WordPress resolves the template on frontend requests.
		$this->preview_engine->register_preview_hooks();
	}

	private function define_admin_hooks(): void {
		$admin = new VB_Admin( $this->plugin_name, $this->version, $this->key_storage, $this->key_manager );

		add_action( 'admin_menu', array( $admin, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_scripts' ) );
		add_action( 'admin_init', array( $admin, 'maybe_redirect_onboarding' ) );
	}

	private function define_api_hooks(): void {
		$rest_api = new VB_REST_API(
			$this->key_storage,
			$this->key_manager,
			$this->session_manager,
			$this->ai_router,
			$this->theme_parser,
			$this->theme_writer,
			$this->theme_exporter,
			$this->preview_engine,
		);

		add_action( 'rest_api_init', array( $rest_api, 'register_routes' ) );
	}

	public function get_key_storage(): VB_Key_Storage {
		return $this->key_storage;
	}

	public function get_key_manager(): VB_Key_Manager {
		return $this->key_manager;
	}
}
