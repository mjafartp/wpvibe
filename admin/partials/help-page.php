<?php
/**
 * Help & Docs admin page.
 *
 * @package WPVibe
 */

defined( 'ABSPATH' ) || exit;

$has_key   = ( new VB_Key_Storage() )->has_key();
$key_type  = get_option( 'wpvibe_key_type', '' );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Help & Documentation', 'wpvibe' ); ?></h1>

	<div style="max-width:800px;">

		<!-- Quick Start -->
		<div class="card" style="max-width:100%;margin-top:20px;">
			<h2><?php esc_html_e( 'Quick Start Guide', 'wpvibe' ); ?></h2>
			<ol style="line-height:2;">
				<li><?php esc_html_e( 'Set up your API key in Settings or the onboarding wizard.', 'wpvibe' ); ?></li>
				<li><?php esc_html_e( 'Open the Theme Editor from the WPVibe menu.', 'wpvibe' ); ?></li>
				<li><?php esc_html_e( 'Type a prompt describing your desired theme (e.g., "Create a modern blog theme with a hero section").', 'wpvibe' ); ?></li>
				<li><?php esc_html_e( 'The AI will generate theme files and update the live preview automatically.', 'wpvibe' ); ?></li>
				<li><?php esc_html_e( 'Continue the conversation to refine the design — the AI remembers context.', 'wpvibe' ); ?></li>
				<li><?php esc_html_e( 'Click "Apply Theme" to activate it, or "Export" to download as a ZIP file.', 'wpvibe' ); ?></li>
			</ol>
		</div>

		<!-- Status -->
		<div class="card" style="max-width:100%;">
			<h2><?php esc_html_e( 'Current Status', 'wpvibe' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'API Key', 'wpvibe' ); ?></th>
					<td>
						<?php if ( $has_key ) : ?>
							<span style="color:#00a32a;">&#10003; <?php esc_html_e( 'Configured', 'wpvibe' ); ?></span>
							<span class="description"> (<?php echo esc_html( $key_type ); ?>)</span>
						<?php else : ?>
							<span style="color:#d63638;">&#10007; <?php esc_html_e( 'Not configured', 'wpvibe' ); ?></span>
							— <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpvibe-settings' ) ); ?>"><?php esc_html_e( 'Set up now', 'wpvibe' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'PHP Version', 'wpvibe' ); ?></th>
					<td><?php echo esc_html( PHP_VERSION ); ?> <?php echo version_compare( PHP_VERSION, '8.1', '>=' ) ? '<span style="color:#00a32a;">&#10003;</span>' : '<span style="color:#d63638;">&#10007; 8.1+ required</span>'; ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'OpenSSL', 'wpvibe' ); ?></th>
					<td><?php echo extension_loaded( 'openssl' ) ? '<span style="color:#00a32a;">&#10003; ' . esc_html__( 'Available', 'wpvibe' ) . '</span>' : '<span style="color:#d63638;">&#10007; ' . esc_html__( 'Missing — required for key encryption', 'wpvibe' ) . '</span>'; ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'cURL', 'wpvibe' ); ?></th>
					<td><?php echo extension_loaded( 'curl' ) ? '<span style="color:#00a32a;">&#10003; ' . esc_html__( 'Available', 'wpvibe' ) . '</span>' : '<span style="color:#d63638;">&#10007; ' . esc_html__( 'Missing — required for AI streaming', 'wpvibe' ) . '</span>'; ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'ZipArchive', 'wpvibe' ); ?></th>
					<td><?php echo class_exists( 'ZipArchive' ) ? '<span style="color:#00a32a;">&#10003; ' . esc_html__( 'Available', 'wpvibe' ) . '</span>' : '<span style="color:#d63638;">&#10007; ' . esc_html__( 'Missing — required for theme export', 'wpvibe' ) . '</span>'; ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Plugin Version', 'wpvibe' ); ?></th>
					<td><?php echo esc_html( WPVIBE_VERSION ); ?></td>
				</tr>
			</table>
		</div>

		<!-- Tips -->
		<div class="card" style="max-width:100%;">
			<h2><?php esc_html_e( 'Prompting Tips', 'wpvibe' ); ?></h2>
			<ul style="line-height:2;list-style:disc;padding-left:20px;">
				<li><?php esc_html_e( 'Be specific: "Create a portfolio theme with a dark navy hero, grid of project cards, and a contact form" works better than "Make a nice theme".', 'wpvibe' ); ?></li>
				<li><?php esc_html_e( 'Iterate incrementally: start broad, then refine individual sections.', 'wpvibe' ); ?></li>
				<li><?php esc_html_e( 'Upload reference images: screenshots or mockups help the AI match a specific design.', 'wpvibe' ); ?></li>
				<li><?php esc_html_e( 'Request specific changes: "Make the header font bigger" or "Change the primary color to teal".', 'wpvibe' ); ?></li>
				<li><?php esc_html_e( 'Use the version history: if a change goes wrong, undo to the previous version and try a different prompt.', 'wpvibe' ); ?></li>
			</ul>
		</div>

		<!-- Keyboard Shortcuts -->
		<div class="card" style="max-width:100%;">
			<h2><?php esc_html_e( 'Keyboard Shortcuts', 'wpvibe' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><kbd>Ctrl</kbd>+<kbd>Enter</kbd></th>
					<td><?php esc_html_e( 'Send message', 'wpvibe' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><kbd>Ctrl</kbd>+<kbd>Z</kbd></th>
					<td><?php esc_html_e( 'Undo last theme version (in preview panel)', 'wpvibe' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>Z</kbd></th>
					<td><?php esc_html_e( 'Redo theme version', 'wpvibe' ); ?></td>
				</tr>
			</table>
		</div>

		<!-- Support -->
		<div class="card" style="max-width:100%;">
			<h2><?php esc_html_e( 'Support', 'wpvibe' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: support URL */
					esc_html__( 'Need help? Visit %s for documentation, tutorials, and support.', 'wpvibe' ),
					'<a href="https://wpvibe.io/docs" target="_blank" rel="noopener">wpvibe.io/docs</a>'
				);
				?>
			</p>
			<p>
				<?php esc_html_e( 'For bug reports or feature requests, please contact support@wpvibe.io.', 'wpvibe' ); ?>
			</p>
		</div>

	</div>
</div>
