# MCP Bridge for Claude

![Version](https://img.shields.io/badge/version-1.3.0-blue)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b?logo=wordpress&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4?logo=php&logoColor=white)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green)

Turn any WordPress site into a remote **[Model Context Protocol](https://modelcontextprotocol.io)** server — so Claude, ChatGPT/Codex, Antigravity, Cursor, VS Code and other MCP-capable AI tools can manage it directly.

> **Development/staging use only.** This plugin can give an AI agent full PHP execution, raw database access, and filesystem control. Read [Security notes](#security-notes) before enabling anything on a production site.

---

## What it does

- Exposes this site as an MCP server over HTTP, secured by a self-contained **OAuth 2.1** authorization server (dynamic client registration, authorization code + PKCE, refresh tokens) — no third-party auth service required.
- A **"Connect your AI tool"** wizard on the settings page covers Claude.ai, Claude Desktop, Claude Code, ChatGPT, Codex CLI, Codex in ChatGPT Desktop, Antigravity, Antigravity CLI, Cursor, VS Code, GitHub Copilot, Windsurf, Cline, Roo Code, Amazon Q, Zed, Kilo Code and OpenCode.
  - GUI apps sign in automatically via OAuth — paste the URL, approve in-browser.
  - CLI tools and editor extensions that read a static config file instead get a ready-made JSON/TOML snippet plus a one-click **generated access token** (a long-lived personal Bearer token, revokable any time).
- Implements the MCP **`prompts`** capability as a lightweight "skills" store: markdown files saved to a configured directory become reusable prompts any connected client can list and recall.
- Returns operating instructions in `initialize`, so a connecting client knows what's enabled and how to use it without being told.

## Tool categories

Every category can be turned on or off independently from **Settings → MCP Bridge**. A disabled category is invisible to a connected AI tool, not just blocked when called.

| Category | Default | Tools |
|---|---|---|
| PHP execution | **off** | `execute_php`, `run_wp_cli` |
| Database (raw SQL) | **off** | `run_sql` |
| Filesystem | on | `read_file`, `write_file`, `edit_file`, `delete_file`, `move_file`, `list_directory`, `create_upload_link` |
| Plugins | on | `list_plugins`, `set_plugin_active` |
| Posts & pages | on | `list_posts`, `get_post`, `create_post`, `update_post`, `delete_post`, `search_content`, `list_terms`, `create_term`, `delete_term` |
| Media library | on | `list_media`, `upload_media`, `delete_media` |
| Comments | on | `list_comments`, `moderate_comment` |
| Users | **off** | `list_users`, `create_user`, `update_user`, `delete_user` |
| Site settings | on | `get_option_value`, `update_option_value`, `switch_theme`, `flush_rewrite_rules` |
| Admin login links | on | `create_admin_login_link` |
| Diagnostics (read-only) | on | `get_site_info`, `list_themes`, `get_error_log` |

**PHP execution** and **Database** are equivalent to full code execution / unrestricted data access, so turning either **on** requires typing a confirmation phrase on the settings page — every other category is a plain toggle.

## Installation

1. Upload the `mcp-bridge` folder to `/wp-content/plugins/`, or install the zip through **Plugins → Add New → Upload Plugin**.
2. Activate the plugin.
3. Go to **Settings → MCP Bridge** and copy the connector URL.
4. Pick your AI tool from the "Connect your AI tool" wizard and follow its steps (OAuth for GUI apps, a generated token + config snippet for CLI tools).
5. Approve the connection request when prompted (you must be logged in to WordPress as an administrator).
6. Optionally enable more tool categories (PHP execution, database, users) if this is a dev/staging site.

## Skills (MCP prompts)

Markdown files saved under the configured skills directory (`wp-content/mcp-bridge-skills` by default, managed through the filesystem tools — no separate UI) are exposed as MCP prompts via `prompts/list` and `prompts/get`, so a connected AI can save and recall reusable skills across sessions.

## Security notes

- Only users with `manage_options` (administrators) can approve a connector, generate an access token, or use the tools.
- Tokens are opaque, stored as SHA-256 hashes, and revokable any time — individually (generated access tokens) or all at once (OAuth sessions, in the Danger Zone).
- A generated access token is exactly as powerful as an OAuth session for the same user and never expires on its own — treat it like a password. It's shown once, at generation time, never stored in recoverable form.
- PHP execution and raw database access are off by default and require explicit, confirmed opt-in.
- Filesystem tools resolve relative paths against a configurable root (defaults to the WordPress install directory).
- The upload-link and admin-login-link endpoints re-check that their tool category is still enabled at the moment they're used, not just when the link was created.

## Known gaps vs. a full site-builder AI plugin

A few deliberately out-of-scope, product-specific subsystems some larger WordPress AI plugins include aren't replicated here — they're large enough to be their own project and aren't core "server management" capability:

- **Gutenberg block editing** relies on `create_post`/`update_post` writing raw block markup directly, with a best-effort warning (via WordPress's own block registry) when a block isn't registered server-side — there's no hidden-browser-editor pipeline to render third-party blocks exactly as a real editor would.
- No bespoke content-linting or design-token system; theme changes go through the standard `switch_theme` / `get_option_value` / `update_option_value` / file tools instead of a dedicated product feature.
- "Skills" are plain files, not a database-backed, multi-source registry other plugins can programmatically contribute to.

## License

GPL-2.0-or-later
