# Contributing to WP Vibe

Thank you for considering contributing to WP Vibe! Every contribution — big or small — makes this project better for the entire WordPress community.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [How to Contribute](#how-to-contribute)
- [Development Setup](#development-setup)
- [Coding Standards](#coding-standards)
- [Pull Request Process](#pull-request-process)
- [Adding a New AI Provider](#adding-a-new-ai-provider)
- [Reporting Security Vulnerabilities](#reporting-security-vulnerabilities)

---

## Code of Conduct

Be kind, be inclusive, be constructive. We follow the [WordPress Community Code of Conduct](https://make.wordpress.org/handbook/community-code-of-conduct/).

---

## How to Contribute

### Report a Bug
- Check existing [issues](https://github.com/wpvibe/wp-vibe/issues) first
- Open a new issue using the **Bug Report** template
- Include: WordPress version, PHP version, plugin version, steps to reproduce, expected vs actual behavior

### Request a Feature
- Open a [GitHub Discussion](https://github.com/wpvibe/wp-vibe/discussions) first to gauge interest
- If there's clear support, open a Feature Request issue

### Submit a Pull Request
See the [Pull Request Process](#pull-request-process) section below.

---

## Development Setup

```bash
# Fork and clone
git clone https://github.com/YOUR_USERNAME/wp-vibe.git
cd wp-vibe

# Install all dependencies
npm install
composer install

# Build the frontend
npm run build

# Or watch mode during development
npm run dev
```

### Local WordPress Environment

Use any local WordPress setup:
- [Local by Flywheel](https://localwp.com/) (easiest)
- [Laravel Valet](https://laravel.com/docs/valet) + [Valet+](https://github.com/weprovide/valet-plus)
- [DDEV](https://ddev.readthedocs.io/)
- [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) (official WP tool)

Symlink the plugin into your WP install:

```bash
ln -s /path/to/wp-vibe /path/to/wordpress/wp-content/plugins/wp-vibe
```

---

## Coding Standards

### PHP

We follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).

```bash
# Check your code
composer lint

# Auto-fix where possible
composer lint:fix
```

Key rules:
- Use `$wpdb->prepare()` for all database queries — never interpolate
- Sanitize all inputs (`sanitize_text_field`, `absint`, etc.)
- Escape all outputs (`esc_html`, `esc_attr`, `esc_url`, etc.)
- Verify nonces on every REST endpoint
- Check capabilities (`current_user_can('edit_themes')`) before privileged actions
- Never log or expose API keys in any form

### TypeScript / React

- Strict TypeScript — no `any` types, no `@ts-ignore`
- All Tailwind classes must use the `vb-` prefix (this is enforced to avoid WP admin conflicts)
- Components go in their own folder with `ComponentName.tsx`
- State lives in Zustand store (`editorStore.ts`) — no prop drilling
- API calls go through `api/wordpress.ts` — no `fetch()` calls in components directly

### Commit Messages

Use [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add Mistral AI provider
fix: prevent XSS in preview iframe URL
docs: update Figma integration guide
chore: bump vite to 6.4.2
test: add PHPUnit tests for key storage encryption
refactor: extract security scan prompt to separate method
```

---

## Pull Request Process

1. **Branch from `main`** — `git checkout -b feat/your-feature`
2. **Keep PRs focused** — one feature or fix per PR
3. **Add tests** — PHP changes need PHPUnit tests, complex TS logic needs unit tests
4. **Update docs** — if you add a feature, update `README.md`
5. **Fill out the PR template** — describe what changed and why
6. **Pass all checks** — CI must pass (lint, build, tests)
7. **Request review** — tag `@jafaronly` for review

PRs that touch security-sensitive code (key storage, file writes, REST endpoints) require extra scrutiny and may take longer to review. That's intentional — please be patient.

---

## Adding a New AI Provider

This is one of the highest-value contributions you can make. Here's exactly how:

### 1. Create the provider class

```php
// includes/class-vb-{provider}.php

class VB_{Provider} {

    /**
     * Stream a chat completion (yields SSE chunks to output buffer).
     */
    public function stream(
        array $messages,
        string $model,
        string $system_prompt,
        string $api_key,
        int $max_tokens = 8192,
    ): void {
        // Implementation
    }

    /**
     * Non-streaming completion (returns full response string).
     * Used for security scanning.
     */
    public function complete(
        array $messages,
        string $model,
        string $system_prompt,
        string $api_key,
        int $max_tokens = 8192,
    ): string {
        // Implementation
    }
}
```

### 2. Add key detection

In `class-vb-key-manager.php`, add your provider's key format to `detect_key_type()`:

```php
// Example for a provider with keys starting with "sk-mistral-"
if (str_starts_with($key, 'sk-mistral-')) {
    return 'mistral';
}
```

### 3. Add routing

In `class-vb-ai-router.php`, add cases to both `route_request()` (streaming) and `complete_response()` (non-streaming):

```php
'mistral' => $this->mistral->stream($messages, $model, $system_prompt, $api_key),
'mistral' => $this->mistral->complete($messages, $model, $system_prompt, $api_key),
```

### 4. Add model list

In `class-vb-rest-api.php` → `handle_get_models()`, add the model list for your provider:

```php
'mistral' => [
    ['id' => 'mistral-large-latest', 'name' => 'Mistral Large', 'recommended' => true],
    ['id' => 'mistral-small-latest', 'name' => 'Mistral Small', 'recommended' => false],
],
```

### 5. Load the class

In `wpvibe.php`, add:
```php
require_once WPVIBE_PLUGIN_DIR . 'includes/class-vb-{provider}.php';
```

### 6. Add tests

Create `tests/test-vb-{provider}.php` with basic PHPUnit tests.

---

## Reporting Security Vulnerabilities

**Do not open a public GitHub issue for security vulnerabilities.**

Email **security@wpvibe.net** with:
- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Your suggested fix (optional but appreciated)

We'll acknowledge within 48 hours and work with you on a coordinated disclosure.

---

## Recognition

All contributors are listed in [README.md](README.md#contributors). We use the [All Contributors](https://allcontributors.org/) spec — contributions of all kinds count (code, docs, design, testing, ideas).

To add yourself after your PR is merged, comment on your PR:
```
@all-contributors please add @username for code,docs
```

---

Thank you for helping make WP Vibe better for everyone. 🙏
