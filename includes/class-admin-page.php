<?php

declare(strict_types=1);

namespace MCPBridge;

if (!defined('ABSPATH')) {
    exit;
}

class Admin_Page
{
    private const RISKY_CATEGORIES = ['php', 'database'];

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_mcpb_save_settings', [self::class, 'save_settings']);
        add_action('admin_post_mcpb_revoke_all', [self::class, 'revoke_all']);
        add_action('admin_post_mcpb_revoke_token', [self::class, 'revoke_token']);
        add_action('wp_ajax_mcpb_generate_token', [self::class, 'ajax_generate_token']);
    }

    public static function menu(): void
    {
        add_options_page(
            'MCP Bridge for Claude',
            'MCP Bridge',
            'manage_options',
            'mcp-bridge',
            [self::class, 'render']
        );
    }

    private static function category_meta(): array
    {
        return [
            'php' => ['label' => 'PHP execution', 'desc' => 'execute_php runs arbitrary PHP code, and run_wp_cli runs WP-CLI commands, both in the WordPress runtime. Equivalent to full code execution on this server.', 'risky' => true],
            'database' => ['label' => 'Database (raw SQL)', 'desc' => 'run_sql executes raw SQL directly via $wpdb. Can read or destroy any data, including password hashes.', 'risky' => true],
            'filesystem' => ['label' => 'Filesystem', 'desc' => 'Read, write, edit, move, delete files and list directories on the server, plus one-time upload links for large/binary files.', 'risky' => false],
            'plugins' => ['label' => 'Plugins', 'desc' => 'List, activate and deactivate plugins.', 'risky' => false],
            'content' => ['label' => 'Posts & pages', 'desc' => 'Create, read, update, delete and search posts, pages and taxonomy terms.', 'risky' => false],
            'media' => ['label' => 'Media library', 'desc' => 'List, upload and delete media library files.', 'risky' => false],
            'comments' => ['label' => 'Comments', 'desc' => 'List and moderate comments.', 'risky' => false],
            'users' => ['label' => 'Users', 'desc' => 'Create, update and delete WordPress user accounts, including roles and passwords.', 'risky' => false],
            'settings' => ['label' => 'Site settings', 'desc' => 'Read/write wp_options values, switch the active theme, flush rewrite rules.', 'risky' => false],
            'admin_access' => ['label' => 'Admin login links', 'desc' => 'Create one-time, short-lived wp-admin login links for browser-automation tools. The real secret is never placed in a navigable URL.', 'risky' => false],
            'diagnostics' => ['label' => 'Diagnostics (read-only)', 'desc' => 'Site info, theme list, error log tail. Nothing here changes the site.', 'risky' => false],
        ];
    }

    public static function save_settings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed');
        }
        check_admin_referer('mcpb_save_settings');

        $current = Tools::enabled_categories();
        $confirmed = (($_POST['confirm_enable'] ?? '') === 'ENABLE');
        $blocked = false;
        $new = [];

        foreach (array_keys(Tools::default_categories()) as $category) {
            $wants = isset($_POST['cat_' . $category]);
            $turning_on = $wants && !$current[$category];

            if ($turning_on && in_array($category, self::RISKY_CATEGORIES, true) && !$confirmed) {
                $new[$category] = $current[$category]; // reject the change, keep previous state
                $blocked = true;
                continue;
            }

            $new[$category] = $wants;
        }

        update_option('mcpb_tool_categories', $new);

        $fs_root = isset($_POST['fs_root']) ? rtrim(sanitize_text_field(wp_unslash($_POST['fs_root'])), '/') : ABSPATH;
        update_option('mcpb_fs_root', $fs_root ?: ABSPATH);

        $default_skills_dir = WP_CONTENT_DIR . '/mcp-bridge-skills';
        $skills_dir = isset($_POST['skills_dir']) ? rtrim(sanitize_text_field(wp_unslash($_POST['skills_dir'])), '/') : $default_skills_dir;
        update_option('mcpb_skills_dir', $skills_dir ?: $default_skills_dir);

        $redirect_args = $blocked
            ? ['page' => 'mcp-bridge', 'confirm_required' => '1']
            : ['page' => 'mcp-bridge', 'updated' => '1'];

        wp_redirect(add_query_arg($redirect_args, admin_url('options-general.php')));
        exit;
    }

    public static function revoke_all(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed');
        }
        check_admin_referer('mcpb_revoke_all');

        global $wpdb;
        $wpdb->query("UPDATE {$wpdb->prefix}mcpb_tokens SET revoked = 1");

        wp_redirect(add_query_arg(['page' => 'mcp-bridge', 'revoked' => '1'], admin_url('options-general.php')));
        exit;
    }

    public static function revoke_token(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed');
        }
        check_admin_referer('mcpb_revoke_token');

        $id = (int) ($_POST['token_id'] ?? 0);
        if ($id > 0) {
            Tokens::revoke_manual($id);
        }

        wp_redirect(add_query_arg(['page' => 'mcp-bridge', 'token_revoked' => '1'], admin_url('options-general.php')));
        exit;
    }

    public static function ajax_generate_token(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Not allowed', 403);
        }
        if (!check_ajax_referer('mcpb_generate_token', 'nonce', false)) {
            wp_send_json_error('Bad nonce', 403);
        }

        $label = sanitize_text_field(wp_unslash($_POST['label'] ?? 'MCP client'));
        $token = Tokens::issue_manual(get_current_user_id(), $label ?: 'MCP client');

        wp_send_json_success(['token' => $token]);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $mcp_url = rest_url(MCPB_NAMESPACE . MCPB_MCP_ROUTE);
        $enabled = Tools::enabled_categories();
        $fs_root = get_option('mcpb_fs_root', ABSPATH);
        $skills_dir = Skills::directory();
        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        $tool_count = count(Tools::definitions());

        global $wpdb;
        $active_tokens = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}mcpb_tokens WHERE token_type='access' AND revoked=0 AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())"
        );
        $manual_tokens = count(Tokens::list_manual());
        $risky_on = array_filter(self::RISKY_CATEGORIES, static fn ($c) => !empty($enabled[$c]));
        ?>
        <div class="wrap mcpb-wrap">
            <?php self::styles(); ?>

            <div class="mcpb-hero">
                <div class="mcpb-hero-text">
                    <h1>MCP Bridge</h1>
                    <p>Turns this site into a remote MCP server any AI tool can connect to.</p>
                </div>
                <div class="mcpb-chips">
                    <span class="mcpb-chip mcpb-chip-neutral"><?php echo esc_html((string) $tool_count); ?> tools enabled</span>
                    <span class="mcpb-chip <?php echo ($active_tokens + $manual_tokens) > 0 ? 'mcpb-chip-good' : 'mcpb-chip-neutral'; ?>"><?php echo esc_html((string) ($active_tokens + $manual_tokens)); ?> active session(s)</span>
                    <?php if (!empty($risky_on)) : ?>
                        <span class="mcpb-chip mcpb-chip-warn"><?php echo count($risky_on); ?> high-risk categor<?php echo count($risky_on) === 1 ? 'y' : 'ies'; ?> on</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($env === 'production') : ?>
                <div class="notice notice-warning"><p><strong>This site is set to the "production" environment type.</strong> MCP Bridge gives Claude PHP execution, filesystem, database and plugin control &mdash; it is meant for development/staging sites only.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['updated'])) : ?><div class="notice notice-success"><p>Settings saved.</p></div><?php endif; ?>
            <?php if (isset($_GET['revoked'])) : ?><div class="notice notice-success"><p>All access tokens revoked.</p></div><?php endif; ?>
            <?php if (isset($_GET['token_revoked'])) : ?><div class="notice notice-success"><p>Token revoked.</p></div><?php endif; ?>
            <?php if (isset($_GET['confirm_required'])) : ?><div class="notice notice-error"><p>Type <code>ENABLE</code> in the confirmation box to turn on PHP execution or raw database access. Every other change on the form was still saved.</p></div><?php endif; ?>

            <div class="mcpb-card">
                <div class="mcpb-card-head">
                    <span class="mcpb-step">1</span>
                    <div>
                        <h2>Connect your AI tool</h2>
                        <p class="mcpb-muted">This site's MCP server URL &mdash; every tool below is pointed at it.</p>
                    </div>
                </div>
                <div class="mcpb-url-row">
                    <input type="text" readonly class="mcpb-field mcpb-url-field" id="mcpb-url" value="<?php echo esc_attr($mcp_url); ?>" onclick="this.select()">
                    <button type="button" class="button" id="mcpb-copy-url">Copy</button>
                </div>

                <?php Connect_Guide::render($mcp_url); ?>
            </div>

            <div class="mcpb-card">
                <div class="mcpb-card-head">
                    <span class="mcpb-step">2</span>
                    <div>
                        <h2>Tool categories</h2>
                        <p class="mcpb-muted">A category that's off is invisible to connected AI tools, not just blocked when called.</p>
                    </div>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('mcpb_save_settings'); ?>
                    <input type="hidden" name="action" value="mcpb_save_settings">

                    <div class="mcpb-cat-list">
                        <?php foreach (self::category_meta() as $key => $meta) : ?>
                            <div class="mcpb-cat-row<?php echo $meta['risky'] ? ' mcpb-cat-risky' : ''; ?>">
                                <label class="mcpb-toggle">
                                    <input type="checkbox" name="cat_<?php echo esc_attr($key); ?>" value="1" <?php checked(!empty($enabled[$key])); ?>>
                                    <span class="mcpb-toggle-slider"></span>
                                </label>
                                <div>
                                    <div class="mcpb-cat-label"><?php echo esc_html($meta['label']); ?><?php if ($meta['risky']) : ?> <span class="mcpb-chip mcpb-chip-warn mcpb-chip-sm">high risk</span><?php endif; ?></div>
                                    <p class="mcpb-muted"><?php echo esc_html($meta['desc']); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mcpb-subcard mcpb-subcard-warn">
                        <label for="mcpb-confirm">Confirm risky changes</label>
                        <input type="text" name="confirm_enable" id="mcpb-confirm" class="mcpb-field" placeholder="ENABLE" style="max-width:180px">
                        <p class="mcpb-muted">Type <code>ENABLE</code> here only if you're turning PHP execution or raw database access <strong>on</strong>. Not required for anything else, or for turning things off.</p>
                    </div>

                    <div class="mcpb-field-row">
                        <label for="mcpb-fs-root">Filesystem root</label>
                        <input type="text" name="fs_root" id="mcpb-fs-root" class="mcpb-field" value="<?php echo esc_attr($fs_root); ?>">
                        <p class="mcpb-muted">Relative file paths passed by Claude are resolved against this directory. Defaults to the WordPress install directory.</p>
                    </div>
                    <div class="mcpb-field-row">
                        <label for="mcpb-skills-dir">Skills directory</label>
                        <input type="text" name="skills_dir" id="mcpb-skills-dir" class="mcpb-field" value="<?php echo esc_attr($skills_dir); ?>">
                        <p class="mcpb-muted">Markdown files saved here are exposed as MCP prompts (<code>prompts/list</code> / <code>prompts/get</code>) so a connected AI can save and recall reusable "skills". Defaults to <code>wp-content/mcp-bridge-skills</code>.</p>
                    </div>

                    <?php submit_button('Save settings'); ?>
                </form>
            </div>

            <div class="mcpb-card mcpb-card-danger">
                <div class="mcpb-card-head">
                    <span class="mcpb-step mcpb-step-danger">!</span>
                    <div>
                        <h2>Danger zone</h2>
                        <p class="mcpb-muted">Immediately signs out every connected AI tool. Generated access tokens (above) are revoked individually, not here.</p>
                    </div>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Revoke every connected client immediately?');">
                    <?php wp_nonce_field('mcpb_revoke_all'); ?>
                    <input type="hidden" name="action" value="mcpb_revoke_all">
                    <?php submit_button('Revoke all OAuth sessions', 'delete'); ?>
                </form>
            </div>
        </div>
        <script>
        (function () {
            var btn = document.getElementById('mcpb-copy-url');
            var field = document.getElementById('mcpb-url');
            if (!btn || !field) return;
            btn.addEventListener('click', function () {
                navigator.clipboard.writeText(field.value).then(function () {
                    var old = btn.textContent;
                    btn.textContent = 'Copied!';
                    setTimeout(function () { btn.textContent = old; }, 1500);
                });
            });
        })();
        </script>
        <?php
    }

    private static function styles(): void
    {
        ?>
        <style>
            .mcpb-wrap{max-width:900px}
            .mcpb-wrap h1{margin-bottom:2px}
            .mcpb-hero{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;margin:16px 0 20px}
            .mcpb-hero-text p{margin:2px 0 0;color:#50575e}
            .mcpb-chips{display:flex;flex-wrap:wrap;gap:8px}
            .mcpb-chip{display:inline-block;padding:5px 12px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap}
            .mcpb-chip-sm{padding:2px 8px;font-size:11px;margin-left:6px;vertical-align:1px}
            .mcpb-chip-neutral{background:#f0f0f1;color:#3c434a}
            .mcpb-chip-good{background:#edfaef;color:#00742b}
            .mcpb-chip-warn{background:#fcf3e0;color:#8a5a00}

            .mcpb-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:24px 26px;margin-bottom:20px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
            .mcpb-card-danger{border-color:#f2c9c8}
            .mcpb-card-head{display:flex;gap:14px;align-items:flex-start;margin-bottom:18px}
            .mcpb-card-head h2{margin:0;font-size:16px}
            .mcpb-card-head .mcpb-muted{margin:2px 0 0}
            .mcpb-step{flex:0 0 auto;width:28px;height:28px;border-radius:50%;background:#1d2327;color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700}
            .mcpb-step-danger{background:#d63638}
            .mcpb-muted{color:#646970;font-size:13px;line-height:1.5;margin:4px 0 0}

            .mcpb-field{border:1px solid #8c8f94;border-radius:6px;padding:7px 10px;font-size:13px;width:100%;box-sizing:border-box;max-width:460px}
            .mcpb-url-row{display:flex;gap:8px;align-items:center;margin-bottom:4px}
            .mcpb-url-field{max-width:520px;font-family:Consolas,Monaco,monospace;font-size:12px;background:#f6f7f7}

            .mcpb-field-row{margin:18px 0}
            .mcpb-field-row>label{display:block;font-weight:600;margin-bottom:6px;font-size:13px}

            .mcpb-cat-list{display:flex;flex-direction:column;gap:2px;margin-bottom:20px}
            .mcpb-cat-row{display:flex;gap:14px;align-items:flex-start;padding:12px 4px;border-bottom:1px solid #f0f0f1}
            .mcpb-cat-row:last-child{border-bottom:none}
            .mcpb-cat-risky{background:linear-gradient(to right,#fdf6ec 0,#fdf6ec 4px,transparent 4px)}
            .mcpb-cat-label{font-weight:600;font-size:13px}

            .mcpb-toggle{position:relative;display:inline-block;width:36px;height:20px;flex:0 0 auto;margin-top:2px}
            .mcpb-toggle input{opacity:0;width:0;height:0}
            .mcpb-toggle-slider{position:absolute;inset:0;background:#c3c4c7;border-radius:999px;cursor:pointer;transition:background .15s}
            .mcpb-toggle-slider:before{content:"";position:absolute;width:16px;height:16px;left:2px;top:2px;background:#fff;border-radius:50%;transition:transform .15s;box-shadow:0 1px 2px rgba(0,0,0,.2)}
            .mcpb-toggle input:checked+.mcpb-toggle-slider{background:#2271b1}
            .mcpb-toggle input:checked+.mcpb-toggle-slider:before{transform:translateX(16px)}

            .mcpb-subcard{background:#fafafa;border:1px solid #e0e0e0;border-radius:8px;padding:14px 16px;margin:16px 0}
            .mcpb-subcard-warn{border-color:#f0dfb4;background:#fefbf3}
            .mcpb-subcard label{font-weight:600;font-size:13px;display:block;margin-bottom:6px}

            .mcpb-tool-search{margin:14px 0 16px}
            .mcpb-tool-search input{width:100%;max-width:360px}
            .mcpb-tool-grid{display:flex;flex-direction:column;gap:16px;margin-bottom:8px}
            .mcpb-tool-group-label{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#646970;margin-bottom:8px}
            .mcpb-tool-dot{width:8px;height:8px;border-radius:50%;flex:0 0 auto}
            .mcpb-tool-buttons{display:flex;flex-wrap:wrap;gap:8px}
            .mcpb-tool-btn{position:relative;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:8px 14px;font-size:13px;cursor:pointer;transition:border-color .15s,background .15s}
            .mcpb-tool-btn:hover{border-color:#2271b1}
            .mcpb-tool-btn.mcpb-active{background:#2271b1;border-color:#2271b1;color:#fff}
            .mcpb-tool-btn[hidden]{display:none}

            .mcpb-tool-panel{margin-top:18px;padding:18px 20px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px}
            .mcpb-tool-panel h3{margin:0 0 12px;font-size:15px}
            .mcpb-tool-panel .mcpb-muted{margin-bottom:0}
            .mcpb-steps{margin:0;padding:0;list-style:none;counter-reset:mcpb-step}
            .mcpb-steps li{counter-increment:mcpb-step;position:relative;padding:6px 0 6px 32px;font-size:13px;line-height:1.5}
            .mcpb-steps li:before{content:counter(mcpb-step);position:absolute;left:0;top:5px;width:20px;height:20px;border-radius:50%;background:#1d2327;color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center}
            .mcpb-code-block{background:#1e1e1e;border-radius:6px;overflow:hidden;margin:10px 0}
            .mcpb-code-path{padding:8px 14px;font-size:12px;color:#9aa0a6;border-bottom:1px solid #333;font-family:Consolas,Monaco,monospace}
            .mcpb-code-block pre{margin:0;padding:14px;overflow-x:auto}
            .mcpb-code-block code{color:#d4d4d4;font-size:12px;font-family:Consolas,Monaco,monospace;white-space:pre}
            .mcpb-panel-actions{display:flex;gap:8px;margin-top:10px}

            .mcpb-token-table{width:100%;border-collapse:collapse;margin-top:8px}
            .mcpb-token-table th,.mcpb-token-table td{text-align:left;padding:10px 12px;border-bottom:1px solid #f0f0f1;font-size:13px}
            .mcpb-token-table th{color:#646970;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.03em}

            @media(max-width:600px){.mcpb-hero{flex-direction:column;align-items:flex-start}.mcpb-url-row{flex-direction:column;align-items:stretch}}
        </style>
        <?php
    }
}
