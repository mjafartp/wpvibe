<p align="center">
  <img src="https://wpvibe.net/logo.svg" alt="WP Vibe" width="180" />
</p>

<h1 align="center">WP Vibe — AI-Powered WordPress Theme Generator</h1>

<p align="center">
  Vibe-code your WordPress theme through natural language. No coding required.
</p>

<p align="center">
  <a href="https://github.com/mjafartp/wpvibe/releases"><img src="https://img.shields.io/github/v/release/mjafartp/wpvibe?style=flat-square&color=orange" alt="Latest Release" /></a>
  <a href="https://wordpress.org/plugins/wp-vibe/"><img src="https://img.shields.io/wordpress/plugin/wp-version/wp-vibe?style=flat-square&label=WordPress&color=21759B" alt="WordPress" /></a>
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat-square" alt="PHP 8.1+" />
  <img src="https://img.shields.io/badge/license-GPL--2.0-blue?style=flat-square" alt="GPL-2.0" />
  <a href="https://github.com/mjafartp/wpvibe/stargazers"><img src="https://img.shields.io/github/stars/mjafartp/wpvibe?style=flat-square&color=yellow" alt="Stars" /></a>
  <a href="https://github.com/mjafartp/wpvibe/graphs/contributors"><img src="https://img.shields.io/github/contributors/mjafartp/wpvibe?style=flat-square" alt="Contributors" /></a>
</p>

---

## What is WP Vibe?

WP Vibe is a free, open-source WordPress plugin that turns your WordPress admin into a **split-screen AI theme studio**. Describe what you want — the AI generates and previews your WordPress theme in real time, right inside your dashboard.

- **Talk to your theme** — type prompts, upload mockups, attach Figma frames
- **See it instantly** — live iFrame preview updates as the AI generates code
- **100% free with your own key** — bring your Anthropic, OpenAI, or Gemini key
- **AI security scan** — every generated theme is scanned before you apply it
- **No subscriptions** — use WP Vibe credits or your own API key, forever

```
┌────────────────────────┬─────────────────────────────────────┐
│  💬 Chat               │  🖥 Live Preview                     │
│                        │                                     │
│  > Make a modern hero  │  ┌─────────────────────────────┐   │
│    with gradient and   │  │                             │   │
│    animated CTA        │  │   [ Your theme renders      │   │
│                        │  │     here in real-time ]     │   │
│  < Done! I've created  │  │                             │   │
│    a full-bleed hero   │  └─────────────────────────────┘   │
│    with CSS animation  │  [Apply] [Export ZIP] [Undo]       │
│                        │                                     │
│  [📎 Image] [🎯 Figma] │                                     │
│  [____Type a prompt____│                                     │
└────────────────────────┴─────────────────────────────────────┘
```

---

## Features

### AI Theme Generation
- **Natural language prompts** — describe any design in plain text
- **Streaming responses** — watch code generate word by word via Server-Sent Events
- **Persistent conversation** — the AI remembers your theme's full history per session
- **Multi-file output** — generates `style.css`, `index.php`, `functions.php`, `theme.json`, and more in one response
- **Incremental updates** — only changed files are regenerated, not the whole theme

### Live Preview
- **Split-screen editor** — chat on the left, live preview on the right
- **Viewport toggle** — switch between Mobile (375px), Tablet (768px), and Desktop
- **Instant refresh** — preview auto-updates after every AI response
- **iFrame sandbox** — preview is fully isolated from WP admin styles

### Multi-Provider AI Support
| Provider | Key Format | Cost |
|---|---|---|
| **Anthropic Claude** (direct) | `sk-ant-...` | Your bill |
| **OpenAI / GPT** (direct) | `sk-...` | Your bill |
| **Google Gemini** (via OpenAI compat) | `sk-...` | Your bill |
| **WP Vibe Credits** | `vb_live_...` | Pay-as-you-go credits |

All API calls are made **server-side from PHP** — your key never reaches the browser.

### Image Upload & Vision
- Upload PNG, JPG, WebP design mockups as reference
- "Make my site look like this" — AI replicates layout from your image
- Up to 4 images per message, carried through conversation context
- Compatible with all vision-capable models (Claude, GPT, Gemini)

### Figma Integration
- Connect Figma via Personal Access Token or MCP server
- Select frames directly from your Figma file inside the plugin
- Design tokens (colors, typography, spacing) extracted automatically
- Frame screenshot + component tree sent to AI as context

### Theme Version History
- Every AI response that changes the theme creates a new version snapshot
- **Undo / Redo** — navigate backwards and forwards through the entire history
- Restore any previous version with one click
- **Export ZIP** — download any version as a complete WordPress theme

### AI Security Scanner
- Runs automatically before **Apply** and **Export**
- Scans generated PHP, JS, CSS for: RCE, SQL injection, XSS, CSRF, file inclusion, info disclosure
- Shows findings in a side panel with severity (critical / high / medium / low)
- **Fix button** — asks AI to patch only security issues, preserving all design and content
- **Smart caching** — only re-scans when the theme version has changed since last scan

### Encrypted Key Storage
- API keys encrypted with AES-256-CBC using WordPress secret salts
- Keys never logged, never exposed to frontend JavaScript
- Rate limiting: 30 chat requests/hour per user (configurable)
- Capability check: requires `edit_themes` permission

---

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 6.3+ |
| PHP | 8.1+ |
| PHP extensions | `openssl`, `curl` |
| HTTPS | Required for API calls |

---

## Installation

### From WordPress Admin (recommended)

1. Go to **Plugins → Add New**
2. Search for **WP Vibe**
3. Click **Install Now** → **Activate**

### Manual Install

```bash
# Download the latest release
curl -L https://github.com/mjafartp/wpvibe/releases/latest/download/wp-vibe.zip -o wp-vibe.zip

# Extract to your wp-content/plugins directory
unzip wp-vibe.zip -d /path/to/wp-content/plugins/
```

Then activate from **Plugins** in your WordPress admin.

### From Source (for development)

```bash
# Clone the repo
git clone https://github.com/mjafartp/wpvibe.git
cd wp-vibe

# Install PHP dependencies (dev only — not needed for production)
composer install

# Install frontend dependencies
npm install

# Build the React frontend
npm run build
```

Symlink or copy the folder into your WordPress `wp-content/plugins/` directory and activate.

---

## Quick Start

### Option A — Bring Your Own API Key (100% Free)

1. After activation, go to **WP Vibe → Settings → API Configuration**
2. Choose your provider: **Anthropic**, **OpenAI**, or **OpenAI-compatible**
3. Paste your API key and click **Test Connection**
4. Select your preferred model and click **Save**
5. Open **WP Vibe → Theme Editor** and start chatting

### Option B — Use WP Vibe Credits

1. Go to [wpvibe.net](https://wpvibe.net) → **Sign Up**
2. Copy your API key from the dashboard
3. Paste it in **WP Vibe → Settings → API Configuration**
4. No other setup needed — models are pre-configured

> Credits never expire. $1 = 100 credits. No subscription required.

---

## How to Use the Theme Editor

### Basic Workflow

```
1. Open WP Vibe → Theme Editor
2. Type your prompt (e.g. "Create a minimal blog theme with dark mode")
3. Watch the AI generate your theme live on the right panel
4. Iterate: "Change the hero font to serif", "Add a sticky navbar", etc.
5. When happy — click Apply Theme or Export ZIP
```

### Prompt Examples

```
"Create a SaaS landing page theme with a dark hero, feature grid, and pricing table"

"Make it more minimal — remove the sidebar and increase whitespace"

"Add a full-width testimonials carousel with star ratings"

"Make it mobile-first and fix the nav hamburger menu"
```

### Using Images

Click **📎** in the chat input, upload your mockup or screenshot, and describe what you want:

```
"Replicate this layout as a WordPress theme"
"Make the hero section look exactly like this image"
```

### Using Figma

1. Go to **Settings → Figma Configuration**
2. Add your Figma Personal Access Token
3. In the chat editor, click **🎯 Figma**
4. Paste your Figma file URL and select frames
5. The AI receives the frame screenshot + design tokens as context

### Version History & Undo

- Every theme change creates a version — visible in the top version bar
- Click **Undo / Redo** arrows to navigate versions
- Click any version number to jump to it directly
- All versions are stored per chat session

### Security Scan

When you click **Apply** or **Export**:
1. WP Vibe automatically scans the theme code with AI
2. If issues are found, a side panel shows them by severity
3. Click **Fix & Apply** — AI patches only security issues, preserving your design
4. Or click **Proceed Anyway** if you understand the risks
5. If the theme hasn't changed since the last scan, the scan is skipped instantly

---

## REST API Endpoints

All endpoints are under `/wp-json/wpvibe/v1/` and require nonce authentication.

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/chat` | Send a chat message; streams SSE response |
| `POST` | `/validate-key` | Test an API key |
| `GET` | `/models` | List available models for this key |
| `POST` | `/apply-theme` | Apply a theme version to WordPress |
| `POST` | `/upload-image` | Upload a reference image |
| `POST` | `/figma-connect` | Save Figma configuration |
| `GET` | `/chat-history` | Fetch chat history for a session |
| `DELETE` | `/chat-history` | Clear a session's chat history |
| `GET` | `/theme-versions` | List theme versions for a session |
| `POST` | `/restore-version` | Restore a previous theme version |
| `POST` | `/export-theme` | Export theme as a downloadable ZIP |
| `GET` | `/sessions` | List all chat sessions |
| `POST` | `/sessions` | Create a new chat session |
| `POST` | `/settings` | Save plugin settings |
| `POST` | `/security-scan` | Scan current theme for security issues |
| `POST` | `/security-fix` | Auto-fix found security issues |

---

## Architecture

```
wpvibe/
├── wpvibe.php                  # Plugin bootstrap
├── includes/
│   ├── class-vb-key-manager.php    # Key type detection (4 key formats)
│   ├── class-vb-key-storage.php    # AES-256-CBC encrypted key storage
│   ├── class-vb-ai-router.php      # Routes to correct AI provider
│   ├── class-vb-anthropic.php      # Anthropic API (stream + complete)
│   ├── class-vb-openai.php         # OpenAI API (stream + complete)
│   ├── class-vb-litellm.php        # LiteLLM proxy client
│   ├── class-vb-theme-parser.php   # Parses AI JSON → theme files
│   ├── class-vb-theme-writer.php   # Safe file writes to themes dir
│   ├── class-vb-theme-exporter.php # ZIP export
│   ├── class-vb-preview-engine.php # iFrame preview URL generation
│   ├── class-vb-session-manager.php# Chat session + message storage
│   ├── class-vb-figma-client.php   # Figma REST API client
│   ├── class-vb-portal-client.php  # WP Vibe service portal client
│   └── class-vb-rest-api.php       # All WP REST endpoints (~1,400 lines)
└── src/
    └── editor/
        ├── App.tsx                  # Root React component
        ├── store/editorStore.ts     # Zustand global state
        ├── api/wordpress.ts         # WP REST API client (SSE + fetch)
        ├── components/
        │   ├── ChatPanel/           # Chat UI, message list, input area
        │   ├── PreviewPanel/        # iFrame preview, viewport toggle
        │   └── SecurityPanel/       # Security scan results side panel
        └── hooks/
            ├── useChat.ts
            ├── useStreaming.ts
            └── useThemePreview.ts
```

**Key design decisions:**
- All AI API calls are **server-side PHP only** — React never touches your API key
- AI responses stream via **Server-Sent Events** (SSE) from PHP to React
- AI responses are **structured JSON** — `{ message, files[], preview_html, changes_summary[] }`
- Theme files are written with an **extension allowlist** — only `.php`, `.css`, `.js`, `.json`, `.svg`, `.png`, `.jpg`
- The Zustand store is the **single source of truth** for all editor state

---

## Configuration

### wp-config.php (optional)

For added encryption strength, you can add a custom salt:

```php
define( 'WPVIBE_ENCRYPTION_SALT', 'your-random-32-character-string' );
```

Without this, the plugin falls back to WordPress's built-in `AUTH_KEY` and `SECURE_AUTH_KEY`.

### Available Models (BYOK)

When using your own key, these models are supported out of the box:

**Anthropic:**
- `claude-opus-4-6` — Highest quality
- `claude-sonnet-4-6` — Best balance (recommended default)
- `claude-haiku-4-5` — Fastest, cheapest

**OpenAI:**
- `gpt-4o` — Best quality
- `gpt-4o-mini` — Faster, cheaper
- `o1-preview`, `o1-mini` — Reasoning models

**OpenAI-compatible (Gemini, OpenRouter, etc.):**
- Set a custom base URL in Settings → API Configuration

---

## Development

### Frontend (React + TypeScript)

```bash
cd wp-vibe
npm install
npm run dev    # Watch mode with HMR
npm run build  # Production build → dist/
```

**Tech stack:** React 18, TypeScript, Zustand, Tailwind CSS (prefixed `vb-` to avoid WP admin conflicts), Vite

### Backend (PHP)

```bash
composer install   # Dev dependencies (PHPUnit, etc.)
composer test      # Run PHPUnit tests
composer lint      # PHP_CodeSniffer with WordPress standards
```

**Requirements:** PHP 8.1+, Composer

### Adding a New AI Provider

1. Create `includes/class-vb-{provider}.php` with `stream()` and `complete()` methods
2. Register a new key format in `class-vb-key-manager.php` → `detect_key_type()`
3. Add a route in `class-vb-ai-router.php` → `route_request()` and `complete_response()`
4. Add model list to `class-vb-rest-api.php` → `handle_get_models()`

### File Structure for Frontend Components

```typescript
// All API calls go through src/editor/api/wordpress.ts
// State lives in src/editor/store/editorStore.ts (Zustand)
// Components use vb- prefixed Tailwind classes (never raw Tailwind)
```

---

## Contributing

We welcome contributions of all sizes — from fixing typos to adding new AI providers. Here's how to get involved:

### Ways to Contribute

- **Bug reports** — Open an issue with steps to reproduce
- **Feature requests** — Open a discussion or issue with your idea
- **Pull requests** — Fix a bug, improve docs, or add a feature
- **New AI providers** — Help add support for Mistral, Cohere, Groq, etc.
- **Translations** — Help translate the plugin via GlotPress
- **Testing** — Test on different WordPress / PHP / host configurations and report issues

### Contribution Workflow

```bash
# 1. Fork the repo on GitHub
# 2. Clone your fork
git clone https://github.com/YOUR_USERNAME/wpvibe.git
cd wp-vibe

# 3. Create a feature branch
git checkout -b feature/add-mistral-provider

# 4. Make your changes and commit
git add .
git commit -m "feat: add Mistral AI provider support"

# 5. Push and open a pull request
git push origin feature/add-mistral-provider
```

### Code Standards

- **PHP:** WordPress Coding Standards (enforced via PHPCS)
- **TypeScript:** Strict mode enabled, no `any` types
- **Commits:** Use [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, `chore:`)
- **Tests:** Add PHPUnit tests for any new PHP class or method

### Development Setup

```bash
git clone https://github.com/mjafartp/wpvibe.git
cd wp-vibe
npm install && composer install
npm run build
```

Link the folder into a local WordPress installation (Local, Valet, DDEV, or Docker).

---

## Contributors

[Jafar](https://github.com/mjafartp) &bull; [Claude (Anthropic)](https://claude.ai) &bull; [Subair](https://github.com/sbrsubuvga)

---

## Roadmap

### v1.1 — Polish & Stability
- [ ] Full mobile responsive editor UI
- [ ] Onboarding wizard (first-run experience)
- [ ] Better error messages and recovery
- [ ] PHPUnit test coverage for all REST endpoints

### v1.2 — More Providers
- [ ] Mistral AI support
- [ ] Groq (ultra-fast inference)
- [ ] OpenRouter unified key support
- [ ] Ollama (local models, no API key required)

### v1.3 — Theme Intelligence
- [ ] Theme style presets (Minimal, Bold, Corporate, E-commerce)
- [ ] Color palette extraction from uploaded images
- [ ] Font pairing suggestions
- [ ] WordPress block theme (`theme.json`) full support
- [ ] WooCommerce template generation

### v1.4 — Collaboration
- [ ] Multi-user chat sessions (teams)
- [ ] Share preview links (no login required)
- [ ] Theme version comments
- [ ] Git-style diff viewer for theme changes

### v2.0 — Plugin Generation
- [ ] Generate custom WordPress plugins, not just themes
- [ ] Page builder integration (Elementor, Bricks, Beaver Builder)
- [ ] Gutenberg block generation
- [ ] Custom post type & field generation

---

## Security

We take security seriously — especially since this plugin generates and writes code.

**Reporting a vulnerability:**
Please **do not** open a public GitHub issue for security vulnerabilities. Email us at **security@wpvibe.net** and we'll respond within 48 hours.

**Security measures in the plugin:**
- All REST endpoints require WordPress nonce verification
- File writes restricted to the `wp-content/themes/` directory only
- Extension allowlist before any file is written
- API keys encrypted at rest with AES-256-CBC
- Keys never logged, never sent to browser JS
- Generated code AI-scanned before applying to live site
- Rate limiting: 30 requests/hour per user

---

## Frequently Asked Questions

**Is WP Vibe really free?**
Yes. Bring your own Anthropic, OpenAI, or Gemini API key and pay nothing to us — ever. We only charge for WP Vibe Credits if you prefer not to manage your own key.

**Does it work with any WordPress theme framework?**
It generates standalone WordPress themes following the standard template hierarchy. It works alongside any child theme setup.

**Does it work with page builders?**
Not directly — it generates classic PHP themes. Page builder integration is on the v2.0 roadmap.

**Can I use it on client sites?**
Yes. You can install it on any number of sites. There's no per-site licensing.

**Is my API key safe?**
Your key is encrypted with AES-256-CBC using your WordPress secret keys before being stored. It never reaches your browser or any third-party server (when using BYOK).

**What happens to my generated theme if I deactivate the plugin?**
Your generated themes remain in `wp-content/themes/` — they're standard WordPress themes. Deactivating WP Vibe only removes the editor interface.

---

## License

WP Vibe is open-source software licensed under the **GNU General Public License v2.0**.

```
WP Vibe — AI-Powered WordPress Theme Generator
Copyright (C) 2026 WP Vibe Contributors

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.
```

See [LICENSE](LICENSE) for the full license text.

---

## Support & Community

| Channel | Link |
|---|---|
| Documentation | [wpvibe.net/docs](https://wpvibe.net/docs) |
| GitHub Issues | [github.com/mjafartp/wpvibe/issues](https://github.com/mjafartp/wpvibe/issues) |
| GitHub Discussions | [github.com/mjafartp/wpvibe/discussions](https://github.com/mjafartp/wpvibe/discussions) |
| Email (security) | security@wpvibe.net |

---

<p align="center">
  Built with ❤️ for the WordPress community.<br/>
  If WP Vibe saves you time, <a href="https://github.com/mjafartp/wpvibe">give it a ⭐ on GitHub</a>.
</p>
