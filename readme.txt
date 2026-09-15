=== MCP Bridge for Claude ===
Contributors: Emon Ahammed
Requires at least: 6.0
Requires PHP: 8.0
Stable tag: 1.3.0
License: GPL-2.0-or-later

Turn this WordPress site into a remote MCP server any MCP-capable AI tool can connect to — Claude, ChatGPT/Codex, Antigravity, Cursor, VS Code and more.

== Description ==

MCP Bridge for Claude exposes this WordPress site as a Model Context Protocol
(MCP) server over HTTP, protected by a self-contained OAuth 2.1 authorization
server (dynamic client registration, authorization code + PKCE, refresh
tokens). The settings page has a "Connect your AI tool" picker covering
Claude.ai, Claude Desktop, Claude Code, ChatGPT, Codex CLI, Codex in ChatGPT
Desktop, Antigravity, Antigravity CLI, Cursor, VS Code, GitHub Copilot,
Windsurf, Cline, Roo Code, Amazon Q, Zed, Kilo Code and OpenCode — GUI apps
sign in automatically via OAuth (paste the URL, approve in-browser); CLI
tools and editor extensions that read a static config file instead get a
ready-made JSON/TOML snippet plus a one-click generated access token
(a long-lived personal Bearer token, revokable any time from the same page).
The server also implements the MCP `prompts` capability (see "Skills" below)
and returns operating instructions in `initialize`, so any client knows
what's enabled and how to use it without being told.

Tools are grouped into categories you turn on/off independently from
Settings > MCP Bridge. A disabled category is invisible to Claude, not just
blocked when called.

* **PHP execution** (off by default) - `execute_php` runs arbitrary PHP in the WordPress runtime; `run_wp_cli` runs any WP-CLI command (argv-array `proc_open`, never a shell string, so tool input can't reach a shell as syntax)
* **Database** (off by default) - `run_sql` runs raw SQL via `$wpdb`
* **Filesystem** - `read_file`, `write_file`, `edit_file` (old_string/new_string replace, like a code editor's find-and-replace), `delete_file`, `move_file`, `list_directory`, `create_upload_link` (one-time raw-PUT endpoint for large/binary files, bypassing base64-in-JSON)
* **Plugins** - `list_plugins`, `set_plugin_active`
* **Posts & pages** - `list_posts`, `get_post`, `create_post`, `update_post`, `delete_post`, `search_content`, `list_terms`, `create_term`, `delete_term` (block markup that isn't registered server-side is flagged with a `warnings` field instead of being silently trusted)
* **Media library** - `list_media`, `upload_media`, `delete_media`
* **Comments** - `list_comments`, `moderate_comment`
* **Users** (off by default) - `list_users`, `create_user`, `update_user`, `delete_user`
* **Site settings** - `get_option_value`, `update_option_value`, `switch_theme`, `flush_rewrite_rules`
* **Admin login links** - `create_admin_login_link`: a two-step, header-authenticated exchange for a one-time wp-admin login link, for browser-automation tools. The real secret never appears in a navigable URL, only a ≤60s single-use nonce does, after exchange.
* **Diagnostics** (read-only) - `get_site_info`, `list_themes`, `get_error_log`

**Skills (MCP prompts).** Markdown files saved under the configured skills
directory (`wp-content/mcp-bridge-skills` by default — managed through the
filesystem tools above, no separate UI) are exposed to Claude as MCP
prompts via `prompts/list` and `prompts/get`, so it can save and recall
reusable skills across sessions.

**This plugin is for development and staging sites only.** Giving an AI
agent PHP execution, filesystem and database access on a production site is
a serious security exposure. Turning PHP execution or raw database access
**on** requires typing a confirmation phrase on the settings page; every
other category is a plain checkbox.

== Installation ==

1. Upload the `mcp-bridge` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to Settings > MCP Bridge, copy the connector URL.
4. In Claude, go to Settings > Connectors > Add custom connector and paste the URL.
5. Approve the connection request when prompted (you must be logged in to
   WordPress as an administrator).
6. Optionally enable more tool categories (PHP execution, database, users) on the settings page if this is a dev/staging site.

== Security notes ==

* Only users with `manage_options` (administrators) can approve a connector, generate an access token, or use the tools.
* Tokens are opaque, stored as SHA-256 hashes, and can be revoked any time from the settings page — either individually (generated access tokens) or all at once (OAuth sessions, in the Danger Zone).
* A generated access token is exactly as powerful as an OAuth session for the same user (whatever tool categories are enabled) and never expires on its own, so treat it like a password: it's shown once, at generation time, and never stored in recoverable form.
* PHP execution and raw database access are off by default and require explicit, confirmed opt-in.
* Filesystem tools resolve relative paths against a configurable root (defaults to the WordPress install directory).
* The upload-link and admin-login-link endpoints double-check that their tool category is still enabled at the moment they're used, not just when the link was created.

== Known gaps vs. a full site-builder AI plugin ==

A few deliberately out-of-scope, product-specific subsystems some larger
WordPress AI plugins include are not replicated here, because they're
large enough to be their own project and aren't core "server management"
capability:

* **Gutenberg block editing** relies on `create_post`/`update_post` writing raw block markup directly, with a best-effort warning (via WordPress's own block registry) when a block isn't registered server-side — there's no hidden-browser-editor/SSE pipeline to render third-party blocks exactly as a real editor would.
* No bespoke content-linting or design-token system is included; theme changes go through the standard `switch_theme`/`get_option_value`/`update_option_value`/file tools instead of a dedicated product feature.
* "Skills" are plain files (see above), not a database-backed, multi-source registry that other plugins can programmatically contribute to.
