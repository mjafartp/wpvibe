<?php
/**
 * Theme Editor page — full-screen React app shell.
 */

defined( 'ABSPATH' ) || exit;
?>
<style>
	/* Hide WP admin chrome for full-screen editor */
	#wpcontent { padding-left: 0 !important; }
	#wpbody-content { padding-bottom: 0 !important; }
	#wpfooter { display: none !important; }
	.update-nag, .notice, .updated { display: none !important; }
</style>

<div id="wpvibe-root">
	<div id="wpvibe-editor-root">
		<div style="display: flex; align-items: center; justify-content: center; height: 100vh; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
			<div style="text-align: center;">
				<div style="font-size: 24px; margin-bottom: 8px;">WP Vibe</div>
				<div style="color: #666;">Loading editor...</div>
			</div>
		</div>
	</div>
</div>
