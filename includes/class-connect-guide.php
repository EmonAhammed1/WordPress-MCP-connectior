<?php

declare(strict_types=1);

namespace MCPBridge;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static metadata + rendering for the "Connect your AI tool" picker.
 * Two connection methods exist site-wide: OAuth (automatic sign-in,
 * used by GUI apps that can open a browser) and a generated personal
 * access token (a static Bearer value pasted into a CLI tool's own
 * config file). This class only decides which method each listed tool
 * needs and what its config snippet looks like — it doesn't touch the
 * database itself.
 */
class Connect_Guide
{
    /**
     * method: 'oauth' (paste URL, sign in in-browser, nothing else to do)
     *      or 'config' (paste a JSON/TOML snippet into a file; needs a
     *         generated access token since these clients don't all open
     *         a browser for OAuth reliably).
     */
    public static function tools(): array
    {
        return [
            'claude-ai' => ['label' => 'Claude.ai', 'group' => 'Claude', 'method' => 'oauth',
                'steps' => ['Go to Settings → Connectors.', 'Click "Add custom connector".', 'Paste the URL above and save.', 'Approve the connection request — you’ll be signed in as whichever WordPress admin you’re logged in as.']],
            'claude-desktop' => ['label' => 'Claude Desktop', 'group' => 'Claude', 'method' => 'oauth',
                'steps' => ['Go to Settings → Connectors.', 'Click "Add custom connector".', 'Paste the URL above and save.', 'Approve the connection request in the browser tab that opens.']],
            'claude-code' => ['label' => 'Claude Code', 'group' => 'Claude', 'method' => 'config',
                'config_path' => 'Run this in your project (or edit .mcp.json directly):', 'lang' => 'bash',
                'snippet' => static fn (string $url, string $token) => "claude mcp add --transport http wordpress {$url}",
                'note' => 'Claude Code opens a browser for OAuth automatically — no token needed for this one.'],
            'chatgpt' => ['label' => 'ChatGPT', 'group' => 'ChatGPT', 'method' => 'oauth',
                'steps' => ['Go to Settings → Connectors → Advanced → turn on Developer mode.', 'Click "Create connector".', 'Paste the URL above as the MCP server URL and save.', 'Approve the connection request in the browser tab that opens.'],
                'note' => 'Connector/Developer-mode availability depends on your ChatGPT plan.'],
            'codex-chatgpt-desktop' => ['label' => 'Codex in ChatGPT Desktop', 'group' => 'ChatGPT', 'method' => 'oauth',
                'steps' => ['Open the ChatGPT desktop app → Settings → Connectors.', 'Add a custom connector with the URL above.', 'Approve the connection request when prompted.']],
            'codex-cli' => ['label' => 'Codex CLI', 'group' => 'ChatGPT', 'method' => 'config',
                'config_path' => '~/.codex/config.toml', 'lang' => 'toml',
                'snippet' => static fn (string $url, string $token) => "[mcp_servers.wordpress]\nurl = \"{$url}\"\nbearer_token = \"{$token}\"",
                'note' => 'Codex CLI’s MCP config keys have changed between versions — run "codex mcp --help" if this doesn’t connect.'],
            'antigravity' => ['label' => 'Antigravity', 'group' => 'Antigravity', 'method' => 'config',
                'config_path' => 'Settings → MCP Servers → Add server:', 'lang' => 'json',
                'snippet' => static fn (string $url, string $token) => self::generic_json($url, $token),
                'note' => 'Antigravity is new enough that its exact config format may differ — the two things that must be right are the URL and the "Authorization: Bearer" header.'],
            'antigravity-cli' => ['label' => 'Antigravity CLI', 'group' => 'Antigravity', 'method' => 'config',
                'config_path' => 'its MCP config file:', 'lang' => 'json',
                'snippet' => static fn (string $url, string $token) => self::generic_json($url, $token),
                'note' => 'Antigravity is new enough that its exact config format may differ — the two things that must be right are the URL and the "Authorization: Bearer" header.'],
            'cursor' => ['label' => 'Cursor', 'group' => 'Other editors & CLIs', 'method' => 'config',
                'config_path' => '.cursor/mcp.json (project) or ~/.cursor/mcp.json:', 'lang' => 'json',
                'snippet' => static fn (string $url, string $token) => self::generic_json($url, $token)],
            'vscode' => ['label' => 'VS Code', 'group' => 'Other editors & CLIs', 'method' => 'config',
                'config_path' => '.vscode/mcp.json:', 'lang' => 'json',
                'snippet' => static fn (string $url, string $token) => self::vscode_json($url, $token)],
            'github-copilot' => ['label' => 'GitHub Copilot', 'group' => 'Other editors & CLIs', 'method' => 'config',
                'config_path' => 'Same file as VS Code above (.vscode/mcp.json) — Copilot Chat reads it too:', 'lang' => 'json',
                'snippet' => static fn (string $url, string $token) => self::vscode_json($url, $token)],
            'windsurf' => ['label' => 'Windsurf', 'group' => 'Other editors & CLIs', 'method' => 'config',
                'config_path' => '~/.codeium/windsurf/mcp_config.json:', 'lang' => 'json',
                'snippet' => static fn (string $url, string $token) => self::generic_json($url, $token, 'serverUrl')],
            'cline' => ['label' => 'Cline', 'group' => 'Other editors & CLIs', 'method' => 'config',
                'config_path' => 'Cline panel → MCP Servers → Configure (cline_mcp_settings.json):', 'lang' => 'json',
                'snippet' => static fn (string $url, string $token) => self::generic_json($url, $token)],
            'roo-code' => ['label' => 'Roo Code', 'group' => 'Other editors & CLIs', 'method' => 'config',
                'config_path' => 'Roo Code panel → MCP Servers → Configure (mcp_settings.json):', 'lang' => 'json',
                'snippet' => static fn (string $url, string $token) => self::generic_json($url, $token)],
            'amazon-q' => ['label' => 'Amazon Q', 'group' => 'Other editors & CLIs', 'method' => 'config',
                'config_path' => '~/.aws/amazonq/mcp.json:', 'lang' => 'json',
                'snippet' => static fn (string $url, string $token) => self::generic_json($url, $token)],
            'zed' => ['label' => 'Zed', 'group' => 'Other editors & CLIs', 'method' => 'config',
                'config_path' => 'settings.json → "context_servers":', 'lang' => 'json',
                'snippet' => static fn (string $url, string $token) => self::generic_json($url, $token),
                'note' => 'Zed’s remote-MCP support is newer than its stdio-server support — check Zed’s docs if the key names above have moved on.'],
            'kilo-code' => ['label' => 'Kilo Code', 'group' => 'Other editors & CLIs', 'method' => 'config',
                'config_path' => 'Kilo Code panel → MCP Servers → Configure:', 'lang' => 'json',
                'snippet' => static fn (string $url, string $token) => self::generic_json($url, $token)],
            'opencode' => ['label' => 'OpenCode', 'group' => 'Other editors & CLIs', 'method' => 'config',
                'config_path' => 'opencode.json:', 'lang' => 'json',
                'snippet' => static fn (string $url, string $token) => self::opencode_json($url, $token)],
        ];
    }

    private static function generic_json(string $url, string $token, string $url_key = 'url'): string
    {
        $body = [
            'mcpServers' => [
                'wordpress' => [
                    $url_key => $url,
                    'headers' => ['Authorization' => "Bearer {$token}"],
                ],
            ],
        ];
        return wp_json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private static function vscode_json(string $url, string $token): string
    {
        $body = [
            'servers' => [
                'wordpress' => [
                    'type' => 'http',
                    'url' => $url,
                    'headers' => ['Authorization' => "Bearer {$token}"],
                ],
            ],
        ];
        return wp_json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private static function opencode_json(string $url, string $token): string
    {
        $body = [
            'mcp' => [
                'wordpress' => [
                    'type' => 'remote',
                    'url' => $url,
                    'headers' => ['Authorization' => "Bearer {$token}"],
                ],
            ],
        ];
        return wp_json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private const GROUP_COLORS = [
        'Claude' => '#d97757',
        'ChatGPT' => '#10a37f',
        'Antigravity' => '#4285f4',
        'Other editors & CLIs' => '#6b7280',
    ];

    public static function render(string $mcp_url): void
    {
        $tools = self::tools();
        $groups = [];
        foreach ($tools as $key => $tool) {
            $groups[$tool['group']][] = $key;
        }
        $placeholder_token = 'YOUR_ACCESS_TOKEN';
        ?>
        <p class="mcpb-muted">Pick the AI tool you want to connect. Desktop/web apps sign in automatically via OAuth; CLI tools and editor extensions read a config file and need a generated access token instead.</p>

        <div class="mcpb-tool-search">
            <input type="search" id="mcpb-tool-search" class="mcpb-field" placeholder="Search your AI tool&hellip;">
        </div>

        <div class="mcpb-tool-grid" id="mcpb-tool-grid">
            <?php foreach ($groups as $group => $keys) : ?>
                <div class="mcpb-tool-group" data-group>
                    <div class="mcpb-tool-group-label">
                        <span class="mcpb-tool-dot" style="background:<?php echo esc_attr(self::GROUP_COLORS[$group] ?? '#6b7280'); ?>"></span>
                        <?php echo esc_html($group); ?>
                    </div>
                    <div class="mcpb-tool-buttons">
                        <?php foreach ($keys as $key) : ?>
                            <button type="button" class="mcpb-tool-btn" data-tool="<?php echo esc_attr($key); ?>" data-label="<?php echo esc_attr(strtolower($tools[$key]['label'])); ?>"><?php echo esc_html($tools[$key]['label']); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <p id="mcpb-tool-empty" class="mcpb-muted" hidden>No tool matches that search.</p>
        </div>

        <div id="mcpb-tool-panel" class="mcpb-tool-panel" hidden>
            <h3 id="mcpb-tool-panel-title"></h3>
            <div id="mcpb-tool-panel-oauth" hidden>
                <ol class="mcpb-steps" id="mcpb-tool-panel-steps"></ol>
            </div>
            <div id="mcpb-tool-panel-config" hidden>
                <p class="mcpb-muted" id="mcpb-tool-panel-configpath"></p>
                <div class="mcpb-code-block">
                    <pre><code id="mcpb-tool-panel-snippet"></code></pre>
                </div>
                <div class="mcpb-panel-actions">
                    <button type="button" class="button" id="mcpb-copy-snippet">Copy</button>
                    <button type="button" class="button button-primary" id="mcpb-generate-token">Generate access token</button>
                </div>
                <p class="mcpb-muted">The token only appears once, right here &mdash; it replaces <code><?php echo esc_html($placeholder_token); ?></code> above. Store it somewhere safe; if you lose it, just generate a new one and revoke the old one below.</p>
            </div>
            <p class="mcpb-muted" id="mcpb-tool-panel-note"></p>
        </div>

        <?php if (current_user_can('manage_options')) : $manual = Tokens::list_manual(); ?>
        <h3 style="margin-top:24px">Active access tokens</h3>
        <?php if (empty($manual)) : ?>
            <p class="mcpb-muted">None generated yet.</p>
        <?php else : ?>
            <table class="mcpb-token-table">
                <thead><tr><th>Label</th><th>Created</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($manual as $row) : ?>
                        <tr>
                            <td><?php echo esc_html($row['label']); ?></td>
                            <td><?php echo esc_html($row['created_at']); ?> UTC</td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Revoke this token? Anything using it will stop working immediately.');" style="margin:0">
                                    <?php wp_nonce_field('mcpb_revoke_token'); ?>
                                    <input type="hidden" name="action" value="mcpb_revoke_token">
                                    <input type="hidden" name="token_id" value="<?php echo esc_attr((string) $row['id']); ?>">
                                    <button type="submit" class="button-link" style="color:#d63638">Revoke</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php endif; ?>

        <script>
        (function () {
            var TOOLS = <?php echo wp_json_encode(array_map(static function (array $t) use ($mcp_url, $placeholder_token): array {
                $out = ['label' => $t['label'], 'method' => $t['method'], 'note' => $t['note'] ?? ''];
                if ($t['method'] === 'oauth') {
                    $out['steps'] = $t['steps'];
                } else {
                    $out['config_path'] = $t['config_path'];
                    $out['snippet'] = ($t['snippet'])($mcp_url, $placeholder_token);
                }
                return $out;
            }, $tools)); ?>;
            var PLACEHOLDER = <?php echo wp_json_encode($placeholder_token); ?>;
            var AJAX_URL = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var NONCE = <?php echo wp_json_encode(wp_create_nonce('mcpb_generate_token')); ?>;

            var panel = document.getElementById('mcpb-tool-panel');
            var title = document.getElementById('mcpb-tool-panel-title');
            var oauthBox = document.getElementById('mcpb-tool-panel-oauth');
            var stepsEl = document.getElementById('mcpb-tool-panel-steps');
            var configBox = document.getElementById('mcpb-tool-panel-config');
            var configPathEl = document.getElementById('mcpb-tool-panel-configpath');
            var snippetEl = document.getElementById('mcpb-tool-panel-snippet');
            var noteEl = document.getElementById('mcpb-tool-panel-note');
            var genBtn = document.getElementById('mcpb-generate-token');
            var copyBtn = document.getElementById('mcpb-copy-snippet');
            var buttons = document.querySelectorAll('.mcpb-tool-btn');
            var searchInput = document.getElementById('mcpb-tool-search');
            var groups = document.querySelectorAll('#mcpb-tool-grid [data-group]');
            var emptyMsg = document.getElementById('mcpb-tool-empty');

            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    var q = searchInput.value.trim().toLowerCase();
                    var anyVisible = false;
                    groups.forEach(function (group) {
                        var groupHasMatch = false;
                        group.querySelectorAll('.mcpb-tool-btn').forEach(function (btn) {
                            var match = !q || btn.dataset.label.indexOf(q) !== -1;
                            btn.hidden = !match;
                            if (match) groupHasMatch = true;
                        });
                        group.hidden = !groupHasMatch;
                        if (groupHasMatch) anyVisible = true;
                    });
                    emptyMsg.hidden = anyVisible;
                });
            }

            buttons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    buttons.forEach(function (b) { b.classList.remove('mcpb-active'); });
                    btn.classList.add('mcpb-active');
                    var tool = TOOLS[btn.dataset.tool];
                    show(tool);
                });
            });

            function show(tool) {
                panel.hidden = false;
                title.textContent = tool.label;
                noteEl.textContent = tool.note || '';
                noteEl.hidden = !tool.note;

                if (tool.method === 'oauth') {
                    oauthBox.hidden = false;
                    configBox.hidden = true;
                    stepsEl.innerHTML = '';
                    tool.steps.forEach(function (step) {
                        var li = document.createElement('li');
                        li.textContent = step;
                        stepsEl.appendChild(li);
                    });
                } else {
                    oauthBox.hidden = true;
                    configBox.hidden = false;
                    configPathEl.textContent = tool.config_path;
                    snippetEl.textContent = tool.snippet;
                    genBtn.hidden = tool.snippet.indexOf(PLACEHOLDER) === -1;
                }
            }

            copyBtn.addEventListener('click', function () {
                navigator.clipboard.writeText(snippetEl.textContent).then(function () {
                    var old = copyBtn.textContent;
                    copyBtn.textContent = 'Copied!';
                    setTimeout(function () { copyBtn.textContent = old; }, 1500);
                });
            });

            genBtn.addEventListener('click', function () {
                genBtn.disabled = true;
                genBtn.textContent = 'Generating…';
                var label = (title.textContent || 'MCP client') + ' — ' + new Date().toISOString().slice(0, 10);
                var body = new URLSearchParams({ action: 'mcpb_generate_token', nonce: NONCE, label: label });

                fetch(AJAX_URL, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.success && res.data && res.data.token) {
                            snippetEl.textContent = snippetEl.textContent.split(PLACEHOLDER).join(res.data.token);
                            genBtn.textContent = 'Generated — reload the page to see it in the list below';
                        } else {
                            genBtn.textContent = 'Failed — try again';
                            genBtn.disabled = false;
                        }
                    })
                    .catch(function () {
                        genBtn.textContent = 'Failed — try again';
                        genBtn.disabled = false;
                    });
            });
        })();
        </script>
        <?php
    }
}
