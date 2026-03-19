=== WP Vibe — AI Theme Generator ===
Contributors: wpvibe
Tags: theme generator, AI, theme builder, chat, theme customizer, wp vibe
Requires at least: 6.3
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate and customize WordPress themes using natural language AI chat with a live preview.

== Description ==

WP Vibe lets you create complete WordPress themes by describing what you want in plain English. A split-screen editor shows your AI conversation on the left and a live preview of the generated theme on the right.

**Key Features**

* **AI-Powered Theme Generation** — Describe your ideal theme and watch it come to life in real time.
* **Live Split-Screen Preview** — See every change rendered instantly in a sandboxed iframe with mobile, tablet, and desktop viewports.
* **Multiple AI Providers** — Use your own Anthropic Claude, OpenAI, or a WP Vibe service key.
* **Image References** — Upload mockups or screenshots and the AI will replicate the design.
* **Figma Integration** — Connect your Figma account to convert frames directly into WordPress themes.
* **Theme Version History** — Every generation step is saved so you can undo, redo, or restore any version.
* **One-Click Export** — Download your generated theme as a ZIP file ready to install anywhere.
* **Streaming Responses** — AI responses appear word-by-word using Server-Sent Events for a responsive experience.

**Supported AI Providers**

1. **WP Vibe Service Key** — Get an API key from the WP Vibe portal with managed billing and model selection.
2. **Anthropic Claude** — Use your own `sk-ant-*` API key for direct access to Claude models.
3. **OpenAI / GPT** — Use your own `sk-*` OpenAI API key for GPT and more.
4. **Claude OAuth Token** — Authenticate with your claude.ai account token.

**How It Works**

1. Install and activate the plugin.
2. Enter your API key on the setup screen.
3. Open the Theme Editor from the WP Vibe menu.
4. Type a prompt like "Create a modern portfolio theme with a dark hero section."
5. The AI generates theme files and a live preview updates in real time.
6. Iterate on the design with follow-up prompts.
7. Apply the theme to your site or export it as a ZIP.

== Installation ==

1. Upload the `wpvibe` folder to the `/wp-content/plugins/` directory, or install through the WordPress Plugins screen.
2. Activate the plugin through the Plugins screen.
3. Navigate to **WP Vibe** in the admin sidebar.
4. Complete the setup wizard by entering your API key.
5. Open the Theme Editor to start generating themes.

**Requirements**

* PHP 8.1 or higher
* WordPress 6.3 or higher
* An API key from one of the supported providers
* The PHP `openssl` extension (for encrypted key storage)
* The PHP `curl` extension (for streaming AI responses)
* The PHP `zip` extension (for theme export)

== Frequently Asked Questions ==

= Do I need my own AI API key? =

You can either get a WP Vibe service key from our portal (includes managed billing) or bring your own Anthropic Claude or OpenAI API key.

= Which AI models are supported? =

With Claude: Sonnet 4.5, Opus 4.5, and Haiku 4.5. With OpenAI: GPT and more. With a WP Vibe service key, available models depend on your subscription plan.

= Are my API keys stored securely? =

Yes. API keys are encrypted with AES-256-CBC using your WordPress secret keys before storage. They are never logged, never sent to the browser, and all API calls are made server-side.

= Can I use the generated themes on other sites? =

Yes. Export any theme as a ZIP file and install it on any WordPress 6.3+ site. Generated themes follow WordPress coding standards and work independently of the plugin.

= Does the plugin modify my existing theme? =

No. Generated themes are saved in their own directory under `/wp-content/themes/`. Your current active theme is not affected until you explicitly apply a generated theme.

= Does it support the block editor? =

Yes. Generated themes include `theme.json` for block editor (Gutenberg) compatibility and follow the WordPress template hierarchy.

= Can I upload a design mockup? =

Yes. You can attach up to 4 images per message (PNG, JPG, WebP, up to 10 MB each). The AI will analyze the design and replicate it as a WordPress theme.

= Is Figma integration available? =

Yes. Connect your Figma account with a Personal Access Token, select frames from your files, and the AI will convert them into theme code with extracted design tokens.

== Screenshots ==

1. Split-screen theme editor — chat on the left, live preview on the right.
2. Setup wizard — choose your API provider and enter your key.
3. Model selector — pick the AI model for your session.
4. Image upload — attach mockups for the AI to replicate.
5. Theme version history — browse and restore previous versions.
6. Theme export — download as a ZIP file.

== Changelog ==

= 1.0.0 =
* Initial release.
* AI chat-based theme generation with streaming responses.
* Support for Anthropic Claude, OpenAI, and WP Vibe service keys.
* Live split-screen preview with viewport toggle.
* Image upload and reference system.
* Figma Personal Access Token integration.
* Theme version history with undo/redo.
* One-click theme export as ZIP.
* Encrypted API key storage.
* WordPress REST API backend.

== Upgrade Notice ==

= 1.0.0 =
Initial release of WP Vibe.
