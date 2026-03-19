<?php
/**
 * Onboarding wizard — PHP + vanilla JS, 3-step flow.
 */

defined( 'ABSPATH' ) || exit;

$rest_url   = esc_url_raw( rest_url( 'wpvibe/v1/' ) );
$nonce      = wp_create_nonce( 'wp_rest' );
$editor_url = admin_url( 'admin.php?page=wpvibe' );
?>
<div class="wrap wpvibe-admin">
<div class="vb-onboarding-container" id="vb-onboarding">

	<!-- Step 1: Welcome -->
	<div class="vb-step" id="vb-step-1">
		<div class="vb-step-header">
			<h1><?php esc_html_e( 'Welcome to WPVibe', 'wpvibe' ); ?></h1>
			<p><?php esc_html_e( 'Generate stunning WordPress themes with AI. Let\'s get you set up in under a minute.', 'wpvibe' ); ?></p>
		</div>
		<div class="vb-step-actions">
			<button type="button" class="button button-primary button-hero" onclick="vbOnboarding.showStep(2, 'service')">
				<?php esc_html_e( 'Get API Key from WPVibe Portal', 'wpvibe' ); ?>
			</button>
			<span class="vb-or"><?php esc_html_e( 'or', 'wpvibe' ); ?></span>
			<button type="button" class="button button-secondary button-hero" onclick="vbOnboarding.showStep(2, 'byok')">
				<?php esc_html_e( 'I Have My Own API Key', 'wpvibe' ); ?>
			</button>
		</div>
	</div>

	<!-- Step 2: Key Input -->
	<div class="vb-step" id="vb-step-2" style="display:none;">
		<div class="vb-step-header">
			<h2 id="vb-step-2-title"><?php esc_html_e( 'Enter Your API Key', 'wpvibe' ); ?></h2>
		</div>

		<!-- Service Key Flow -->
		<div id="vb-flow-service" style="display:none;">
			<p><?php esc_html_e( 'Go to the WPVibe Portal, navigate to Account → API Keys, and copy your key.', 'wpvibe' ); ?></p>
			<div class="vb-field-group">
				<label for="vb-service-key"><?php esc_html_e( 'WPVibe API Key', 'wpvibe' ); ?></label>
				<div class="vb-input-with-toggle">
					<input type="password" id="vb-service-key" class="regular-text" placeholder="vb_live_xxxxxxxxxxxxxxxx" />
					<button type="button" class="button vb-toggle-visibility" onclick="vbOnboarding.toggleVisibility('vb-service-key')">
						<?php esc_html_e( 'Show', 'wpvibe' ); ?>
					</button>
				</div>
			</div>
		</div>

		<!-- BYOK Flow -->
		<div id="vb-flow-byok" style="display:none;">
			<div class="vb-field-group">
				<label for="vb-provider"><?php esc_html_e( 'AI Provider', 'wpvibe' ); ?></label>
				<select id="vb-provider" class="regular-text" onchange="vbOnboarding.onProviderChange()">
					<option value="claude_api"><?php esc_html_e( 'Anthropic Claude', 'wpvibe' ); ?></option>
					<option value="openai_codex"><?php esc_html_e( 'OpenAI / Codex', 'wpvibe' ); ?></option>
					<option value="claude_oauth"><?php esc_html_e( 'Claude Token (OAuth)', 'wpvibe' ); ?></option>
				</select>
			</div>
			<div class="vb-field-group">
				<label for="vb-byok-key" id="vb-byok-label"><?php esc_html_e( 'API Key', 'wpvibe' ); ?></label>
				<div class="vb-input-with-toggle">
					<input type="password" id="vb-byok-key" class="regular-text" placeholder="sk-ant-xxxxxxxx" />
					<button type="button" class="button vb-toggle-visibility" onclick="vbOnboarding.toggleVisibility('vb-byok-key')">
						<?php esc_html_e( 'Show', 'wpvibe' ); ?>
					</button>
				</div>
			</div>
		</div>

		<!-- Shared controls -->
		<div class="vb-step-actions" style="margin-top: 20px;">
			<button type="button" class="button button-primary" id="vb-test-btn" onclick="vbOnboarding.testConnection()">
				<?php esc_html_e( 'Test Connection', 'wpvibe' ); ?>
			</button>
			<button type="button" class="button" onclick="vbOnboarding.showStep(1)">
				<?php esc_html_e( 'Back', 'wpvibe' ); ?>
			</button>
			<span id="vb-test-status" class="vb-status"></span>
		</div>
	</div>

	<!-- Step 3: Confirmation -->
	<div class="vb-step" id="vb-step-3" style="display:none;">
		<div class="vb-step-header">
			<div class="vb-success-icon">&#10003;</div>
			<h2><?php esc_html_e( 'You\'re All Set!', 'wpvibe' ); ?></h2>
			<p id="vb-success-message"><?php esc_html_e( 'Your API key has been validated and saved.', 'wpvibe' ); ?></p>
		</div>

		<div class="vb-model-selector" id="vb-model-selector" style="display:none;">
			<label for="vb-model-select"><?php esc_html_e( 'Preferred AI Model', 'wpvibe' ); ?></label>
			<select id="vb-model-select" class="regular-text"></select>
		</div>

		<div class="vb-step-actions" style="margin-top: 20px;">
			<a href="<?php echo esc_url( $editor_url ); ?>" class="button button-primary button-hero" id="vb-open-editor" onclick="vbOnboarding.completeOnboarding()">
				<?php esc_html_e( 'Open Theme Editor', 'wpvibe' ); ?>
			</a>
		</div>
	</div>

</div>
</div>

<script>
(function() {
	'use strict';

	var REST_URL = <?php echo wp_json_encode( $rest_url ); ?>;
	var NONCE = <?php echo wp_json_encode( $nonce ); ?>;

	var currentFlow = 'service';
	var validatedModels = [];

	window.vbOnboarding = {
		showStep: function(step, flow) {
			var steps = document.querySelectorAll('.vb-step');
			for (var i = 0; i < steps.length; i++) {
				steps[i].style.display = 'none';
			}
			document.getElementById('vb-step-' + step).style.display = 'block';

			if (step === 2 && flow) {
				currentFlow = flow;
				document.getElementById('vb-flow-service').style.display = flow === 'service' ? 'block' : 'none';
				document.getElementById('vb-flow-byok').style.display = flow === 'byok' ? 'block' : 'none';

				var title = document.getElementById('vb-step-2-title');
				title.textContent = flow === 'service'
					? <?php echo wp_json_encode( __( 'Enter Your WPVibe Key', 'wpvibe' ) ); ?>
					: <?php echo wp_json_encode( __( 'Enter Your API Key', 'wpvibe' ) ); ?>;
			}
		},

		onProviderChange: function() {
			var provider = document.getElementById('vb-provider').value;
			var label = document.getElementById('vb-byok-label');
			var input = document.getElementById('vb-byok-key');

			var config = {
				claude_api:   { label: 'Anthropic API Key', placeholder: 'sk-ant-xxxxxxxx' },
				openai_codex: { label: 'OpenAI API Key',    placeholder: 'sk-xxxxxxxx' },
				claude_oauth: { label: 'OAuth Token',       placeholder: 'eyJhbGci...' }
			};

			var c = config[provider] || config.claude_api;
			label.textContent = c.label;
			input.placeholder = c.placeholder;
		},

		toggleVisibility: function(inputId) {
			var input = document.getElementById(inputId);
			var btn = input.nextElementSibling;
			if (input.type === 'password') {
				input.type = 'text';
				btn.textContent = <?php echo wp_json_encode( __( 'Hide', 'wpvibe' ) ); ?>;
			} else {
				input.type = 'password';
				btn.textContent = <?php echo wp_json_encode( __( 'Show', 'wpvibe' ) ); ?>;
			}
		},

		testConnection: function() {
			var btn = document.getElementById('vb-test-btn');
			var status = document.getElementById('vb-test-status');
			var key = '';

			if (currentFlow === 'service') {
				key = document.getElementById('vb-service-key').value.trim();
			} else {
				key = document.getElementById('vb-byok-key').value.trim();
			}

			if (!key) {
				status.textContent = <?php echo wp_json_encode( __( 'Please enter an API key.', 'wpvibe' ) ); ?>;
				status.className = 'vb-status vb-status-error';
				return;
			}

			btn.disabled = true;
			btn.textContent = <?php echo wp_json_encode( __( 'Testing...', 'wpvibe' ) ); ?>;
			status.textContent = '';
			status.className = 'vb-status';

			var self = this;

			fetch(REST_URL + 'validate-key', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': NONCE
				},
				body: JSON.stringify({ key: key })
			})
			.then(function(r) { return r.json(); })
			.then(function(data) {
				btn.disabled = false;
				btn.textContent = <?php echo wp_json_encode( __( 'Test Connection', 'wpvibe' ) ); ?>;

				if (data.valid) {
					status.textContent = data.message;
					status.className = 'vb-status vb-status-success';
					validatedModels = data.models || [];

					setTimeout(function() {
						self.showStep(3);
						self.populateModels(validatedModels);
					}, 800);
				} else {
					status.textContent = data.message || <?php echo wp_json_encode( __( 'Validation failed.', 'wpvibe' ) ); ?>;
					status.className = 'vb-status vb-status-error';
				}
			})
			.catch(function() {
				btn.disabled = false;
				btn.textContent = <?php echo wp_json_encode( __( 'Test Connection', 'wpvibe' ) ); ?>;
				status.textContent = <?php echo wp_json_encode( __( 'Network error. Please try again.', 'wpvibe' ) ); ?>;
				status.className = 'vb-status vb-status-error';
			});
		},

		populateModels: function(models) {
			var container = document.getElementById('vb-model-selector');
			var select = document.getElementById('vb-model-select');

			if (!models.length) {
				container.style.display = 'none';
				return;
			}

			// Clear existing options using safe DOM methods.
			while (select.firstChild) {
				select.removeChild(select.firstChild);
			}

			for (var i = 0; i < models.length; i++) {
				var m = models[i];
				var opt = document.createElement('option');
				opt.value = m.id;
				opt.textContent = m.name + (m.recommended ? ' (Recommended)' : '') + ' \u2014 ' + m.description;
				if (m.recommended) { opt.selected = true; }
				select.appendChild(opt);
			}
			container.style.display = 'block';
		},

		completeOnboarding: function() {
			var model = document.getElementById('vb-model-select').value || '';

			fetch(REST_URL + 'save-settings', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': NONCE
				},
				body: JSON.stringify({
					onboarding_complete: true,
					selected_model: model
				})
			}).catch(function() {});
		}
	};
})();
</script>
