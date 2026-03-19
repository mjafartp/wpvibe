<?php
/**
 * Theme History admin page.
 *
 * Lists all theme generation sessions with version snapshots,
 * allowing users to view, restore, or export previous versions.
 *
 * @package WPVibe
 */

defined( 'ABSPATH' ) || exit;

$user_id         = get_current_user_id();
$session_manager = new VB_Session_Manager();
$sessions        = $session_manager->list_sessions( $user_id, 50 );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Theme History', 'wpvibe' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Browse all theme generation sessions and their version snapshots.', 'wpvibe' ); ?></p>

	<?php if ( empty( $sessions ) ) : ?>
		<div class="notice notice-info inline" style="margin-top:20px;">
			<p><?php esc_html_e( 'No themes generated yet. Open the Theme Editor to get started.', 'wpvibe' ); ?></p>
		</div>
	<?php else : ?>

		<table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
			<thead>
				<tr>
					<th scope="col" style="width:30%;"><?php esc_html_e( 'Session', 'wpvibe' ); ?></th>
					<th scope="col" style="width:15%;"><?php esc_html_e( 'Theme Slug', 'wpvibe' ); ?></th>
					<th scope="col" style="width:15%;"><?php esc_html_e( 'Model', 'wpvibe' ); ?></th>
					<th scope="col" style="width:10%;"><?php esc_html_e( 'Versions', 'wpvibe' ); ?></th>
					<th scope="col" style="width:15%;"><?php esc_html_e( 'Created', 'wpvibe' ); ?></th>
					<th scope="col" style="width:15%;"><?php esc_html_e( 'Actions', 'wpvibe' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sessions as $session ) :
					$versions = $session_manager->get_theme_versions( (int) $session['id'] );
					$version_count = count( $versions );
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( $session['session_name'] ); ?></strong>
					</td>
					<td>
						<code><?php echo esc_html( $session['theme_slug'] ?: '—' ); ?></code>
					</td>
					<td><?php echo esc_html( $session['model_used'] ?: '—' ); ?></td>
					<td><?php echo (int) $version_count; ?></td>
					<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $session['created_at'] ) ) ); ?></td>
					<td>
						<?php if ( $version_count > 0 ) : ?>
							<button
								type="button"
								class="button button-small vb-toggle-versions"
								data-session-id="<?php echo (int) $session['id']; ?>"
							>
								<?php esc_html_e( 'View Versions', 'wpvibe' ); ?>
							</button>
						<?php else : ?>
							<span class="description"><?php esc_html_e( 'No versions', 'wpvibe' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( $version_count > 0 ) : ?>
				<tr class="vb-versions-row" data-session-id="<?php echo (int) $session['id']; ?>" style="display:none;">
					<td colspan="6" style="padding:0;">
						<table class="wp-list-table widefat" style="border:none;box-shadow:none;background:#f9f9f9;">
							<thead>
								<tr>
									<th style="width:10%;"><?php esc_html_e( 'Version', 'wpvibe' ); ?></th>
									<th style="width:20%;"><?php esc_html_e( 'Theme', 'wpvibe' ); ?></th>
									<th style="width:15%;"><?php esc_html_e( 'Files', 'wpvibe' ); ?></th>
									<th style="width:20%;"><?php esc_html_e( 'Created', 'wpvibe' ); ?></th>
									<th style="width:15%;"><?php esc_html_e( 'Applied', 'wpvibe' ); ?></th>
									<th style="width:20%;"><?php esc_html_e( 'Actions', 'wpvibe' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $versions as $v ) :
									$files = json_decode( $v['files_snapshot'] ?? '[]', true );
									$file_count = is_array( $files ) ? count( $files ) : 0;
								?>
								<tr>
									<td><strong>v<?php echo (int) $v['version_number']; ?></strong></td>
									<td><code><?php echo esc_html( $v['theme_slug'] ); ?></code></td>
									<td><?php echo (int) $file_count; ?> <?php esc_html_e( 'files', 'wpvibe' ); ?></td>
									<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $v['created_at'] ) ) ); ?></td>
									<td>
										<?php if ( ! empty( $v['applied_at'] ) ) : ?>
											<span style="color:#00a32a;">&#10003; <?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $v['applied_at'] ) ) ); ?></span>
										<?php else : ?>
											<span class="description"><?php esc_html_e( 'Not applied', 'wpvibe' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<a
											href="<?php echo esc_url( admin_url( 'admin.php?page=wpvibe&session=' . (int) $session['id'] ) ); ?>"
											class="button button-small"
										>
											<?php esc_html_e( 'Open in Editor', 'wpvibe' ); ?>
										</a>
									</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</td>
				</tr>
				<?php endif; ?>
				<?php endforeach; ?>
			</tbody>
		</table>

		<script>
		document.addEventListener('DOMContentLoaded', function() {
			document.querySelectorAll('.vb-toggle-versions').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var sid = this.getAttribute('data-session-id');
					var row = document.querySelector('.vb-versions-row[data-session-id="' + sid + '"]');
					if (row) {
						row.style.display = row.style.display === 'none' ? '' : 'none';
					}
				});
			});
		});
		</script>

	<?php endif; ?>
</div>
