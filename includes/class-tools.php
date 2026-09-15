<?php

declare(strict_types=1);

namespace MCPBridge;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Every tool exposed to Claude over MCP, grouped into categories the admin
 * can turn on/off independently from the settings page. `php` and
 * `database` stay off by default and need a typed confirmation to enable
 * (see Admin_Page) because they're equivalent to full code execution /
 * unrestricted data access; everything else defaults to a sensible risk
 * level for a dev/staging site.
 */
class Tools
{
    public static function default_categories(): array
    {
        return [
            'php' => false,
            'database' => false,
            'filesystem' => true,
            'plugins' => true,
            'content' => true,
            'media' => true,
            'comments' => true,
            'users' => false,
            'settings' => true,
            'admin_access' => true,
            'diagnostics' => true,
        ];
    }

    public static function enabled_categories(): array
    {
        $stored = get_option('mcpb_tool_categories');
        return is_array($stored) ? array_merge(self::default_categories(), $stored) : self::default_categories();
    }

    public static function category_enabled(string $category): bool
    {
        return !empty(self::enabled_categories()[$category]);
    }

    private static function is_enabled(string $category): bool
    {
        return self::category_enabled($category);
    }

    /** Master list of tools: MCP fields plus an internal 'category' used for gating. */
    private static function tool_specs(): array
    {
        $string = ['type' => 'string'];
        $int = ['type' => 'integer'];
        $bool = ['type' => 'boolean'];
        $none = ['type' => 'object', 'properties' => new \stdClass()];

        return [
            // -- php --------------------------------------------------------
            [
                'category' => 'php',
                'name' => 'execute_php',
                'description' => 'Execute arbitrary PHP code in the WordPress runtime and return whatever is echoed plus the return value of the last expression.',
                'inputSchema' => ['type' => 'object', 'properties' => ['code' => $string + ['description' => 'PHP code, without the opening <?php tag.']], 'required' => ['code']],
            ],
            [
                'category' => 'php',
                'name' => 'run_wp_cli',
                'description' => 'Run a WP-CLI command on the server and return its stdout/stderr/exit code. Same risk tier as execute_php: it can install/remove plugins, run arbitrary eval, manage users, and touch the database.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'args' => ['type' => 'array', 'items' => $string, 'description' => 'Arguments to pass to wp, e.g. ["plugin", "list", "--format=json"]. Do not include the leading "wp".'],
                        'timeout' => $int + ['description' => 'Seconds to wait before killing the process. Defaults to 60, max 300.'],
                    ],
                    'required' => ['args'],
                ],
            ],

            // -- database -----------------------------------------------------
            [
                'category' => 'database',
                'name' => 'run_sql',
                'description' => 'Run a raw SQL query against the WordPress database using $wpdb. SELECT-like statements return rows; others return affected row count.',
                'inputSchema' => ['type' => 'object', 'properties' => ['query' => $string], 'required' => ['query']],
            ],

            // -- filesystem ---------------------------------------------------
            [
                'category' => 'filesystem',
                'name' => 'read_file',
                'description' => 'Read a text file from the server filesystem.',
                'inputSchema' => ['type' => 'object', 'properties' => ['path' => $string + ['description' => 'Absolute path, or relative to the configured filesystem root.']], 'required' => ['path']],
            ],
            [
                'category' => 'filesystem',
                'name' => 'write_file',
                'description' => 'Write (create or overwrite) a text file on the server filesystem, creating parent directories if needed.',
                'inputSchema' => ['type' => 'object', 'properties' => ['path' => $string, 'content' => $string], 'required' => ['path', 'content']],
            ],
            [
                'category' => 'filesystem',
                'name' => 'delete_file',
                'description' => 'Delete a file or empty directory on the server filesystem.',
                'inputSchema' => ['type' => 'object', 'properties' => ['path' => $string], 'required' => ['path']],
            ],
            [
                'category' => 'filesystem',
                'name' => 'edit_file',
                'description' => 'Edit an existing file by replacing an exact text match with new text — the same pattern as a code editor\'s find-and-replace. old_string must match the file exactly, including whitespace, and must be unique in the file unless replace_all is set.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => $string,
                        'old_string' => $string + ['description' => 'Exact text to find. Read the file first to get this right.'],
                        'new_string' => $string + ['description' => 'Replacement text. Use an empty string to delete old_string.'],
                        'replace_all' => $bool + ['description' => 'Replace every occurrence instead of requiring a single unique match. Defaults to false.'],
                    ],
                    'required' => ['path', 'old_string', 'new_string'],
                ],
            ],
            [
                'category' => 'filesystem',
                'name' => 'move_file',
                'description' => 'Move or rename a file or directory on the server filesystem.',
                'inputSchema' => ['type' => 'object', 'properties' => ['from' => $string, 'to' => $string], 'required' => ['from', 'to']],
            ],
            [
                'category' => 'filesystem',
                'name' => 'list_directory',
                'description' => 'List files and directories at a given path.',
                'inputSchema' => ['type' => 'object', 'properties' => ['path' => $string], 'required' => ['path']],
            ],
            [
                'category' => 'filesystem',
                'name' => 'create_upload_link',
                'description' => 'Create a one-time upload endpoint for sending a large or binary file straight into the filesystem, bypassing base64-in-JSON. Send a raw PUT body with the returned token in the X-MCPB-Upload-Token header.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => $string + ['description' => 'Destination path for the uploaded file.'],
                        'expires_in' => $int + ['description' => 'Seconds the link stays valid. 30-3600, default 900.'],
                        'max_bytes' => $int + ['description' => 'Maximum upload size in bytes. Default 104857600 (100 MiB).'],
                        'overwrite' => $bool + ['description' => 'Allow replacing an existing file at path. Defaults to false.'],
                        'create_directories' => $bool + ['description' => 'Create missing parent directories. Defaults to true.'],
                    ],
                    'required' => ['path'],
                ],
            ],

            // -- plugins --------------------------------------------------------
            [
                'category' => 'plugins',
                'name' => 'list_plugins',
                'description' => 'List installed plugins with their active state and version.',
                'inputSchema' => $none,
            ],
            [
                'category' => 'plugins',
                'name' => 'set_plugin_active',
                'description' => 'Activate or deactivate a plugin by its plugin file (e.g. "akismet/akismet.php").',
                'inputSchema' => ['type' => 'object', 'properties' => ['plugin' => $string, 'active' => $bool], 'required' => ['plugin', 'active']],
            ],

            // -- content: posts/pages/terms -------------------------------------
            [
                'category' => 'content',
                'name' => 'list_posts',
                'description' => 'List posts or pages, optionally filtered by status or a search term.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'post_type' => $string + ['description' => 'e.g. "post" or "page". Defaults to "post".'],
                    'status' => $string + ['description' => 'publish, draft, pending, private, trash, or any. Defaults to "any".'],
                    'search' => $string,
                    'per_page' => $int + ['description' => 'Max 100, default 20.'],
                    'page' => $int,
                ]],
            ],
            [
                'category' => 'content',
                'name' => 'get_post',
                'description' => 'Get the full content, status and metadata of a single post or page by ID.',
                'inputSchema' => ['type' => 'object', 'properties' => ['id' => $int], 'required' => ['id']],
            ],
            [
                'category' => 'content',
                'name' => 'create_post',
                'description' => 'Create a new post or page.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'post_type' => $string + ['description' => 'Defaults to "post".'],
                    'title' => $string,
                    'content' => $string,
                    'excerpt' => $string,
                    'status' => $string + ['description' => 'Defaults to "draft".'],
                    'meta' => ['type' => 'object', 'description' => 'Optional map of meta_key => value.'],
                ], 'required' => ['title']],
            ],
            [
                'category' => 'content',
                'name' => 'update_post',
                'description' => 'Update fields on an existing post or page. Only the fields you pass are changed.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'id' => $int,
                    'title' => $string,
                    'content' => $string,
                    'excerpt' => $string,
                    'status' => $string,
                    'meta' => ['type' => 'object'],
                ], 'required' => ['id']],
            ],
            [
                'category' => 'content',
                'name' => 'delete_post',
                'description' => 'Delete a post or page. By default it is moved to trash.',
                'inputSchema' => ['type' => 'object', 'properties' => ['id' => $int, 'force' => $bool + ['description' => 'Skip trash and delete permanently. Defaults to false.']], 'required' => ['id']],
            ],
            [
                'category' => 'content',
                'name' => 'search_content',
                'description' => 'Full-text search across posts/pages/custom post types.',
                'inputSchema' => ['type' => 'object', 'properties' => ['query' => $string, 'post_type' => $string + ['description' => 'Defaults to "any".'], 'per_page' => $int], 'required' => ['query']],
            ],
            [
                'category' => 'content',
                'name' => 'list_terms',
                'description' => 'List taxonomy terms (categories, tags, or a custom taxonomy).',
                'inputSchema' => ['type' => 'object', 'properties' => ['taxonomy' => $string + ['description' => 'Defaults to "category".'], 'search' => $string, 'per_page' => $int]],
            ],
            [
                'category' => 'content',
                'name' => 'create_term',
                'description' => 'Create a new taxonomy term.',
                'inputSchema' => ['type' => 'object', 'properties' => ['taxonomy' => $string + ['description' => 'Defaults to "category".'], 'name' => $string, 'parent' => $int], 'required' => ['name']],
            ],
            [
                'category' => 'content',
                'name' => 'delete_term',
                'description' => 'Delete a taxonomy term.',
                'inputSchema' => ['type' => 'object', 'properties' => ['taxonomy' => $string + ['description' => 'Defaults to "category".'], 'term_id' => $int], 'required' => ['term_id']],
            ],

            // -- media ----------------------------------------------------------
            [
                'category' => 'media',
                'name' => 'list_media',
                'description' => 'List items in the media library.',
                'inputSchema' => ['type' => 'object', 'properties' => ['per_page' => $int, 'page' => $int]],
            ],
            [
                'category' => 'media',
                'name' => 'upload_media',
                'description' => 'Upload a file to the media library from base64-encoded content.',
                'inputSchema' => ['type' => 'object', 'properties' => ['filename' => $string, 'content_base64' => $string], 'required' => ['filename', 'content_base64']],
            ],
            [
                'category' => 'media',
                'name' => 'delete_media',
                'description' => 'Delete a media library item permanently.',
                'inputSchema' => ['type' => 'object', 'properties' => ['id' => $int], 'required' => ['id']],
            ],

            // -- comments ---------------------------------------------------------
            [
                'category' => 'comments',
                'name' => 'list_comments',
                'description' => 'List comments, optionally filtered by post or status.',
                'inputSchema' => ['type' => 'object', 'properties' => ['post_id' => $int, 'status' => $string + ['description' => 'hold, approve, spam, trash, or all. Defaults to "all".'], 'per_page' => $int]],
            ],
            [
                'category' => 'comments',
                'name' => 'moderate_comment',
                'description' => 'Approve, spam, trash, or permanently delete a comment.',
                'inputSchema' => ['type' => 'object', 'properties' => ['id' => $int, 'action' => $string + ['description' => 'approve, spam, trash, or delete.']], 'required' => ['id', 'action']],
            ],

            // -- users ------------------------------------------------------------
            [
                'category' => 'users',
                'name' => 'list_users',
                'description' => 'List WordPress user accounts.',
                'inputSchema' => ['type' => 'object', 'properties' => ['role' => $string, 'per_page' => $int]],
            ],
            [
                'category' => 'users',
                'name' => 'create_user',
                'description' => 'Create a new WordPress user account.',
                'inputSchema' => ['type' => 'object', 'properties' => ['username' => $string, 'email' => $string, 'password' => $string, 'role' => $string + ['description' => 'Defaults to "subscriber".']], 'required' => ['username', 'email', 'password']],
            ],
            [
                'category' => 'users',
                'name' => 'update_user',
                'description' => 'Update fields on an existing user account.',
                'inputSchema' => ['type' => 'object', 'properties' => ['id' => $int, 'email' => $string, 'password' => $string, 'role' => $string, 'display_name' => $string], 'required' => ['id']],
            ],
            [
                'category' => 'users',
                'name' => 'delete_user',
                'description' => 'Delete a user account.',
                'inputSchema' => ['type' => 'object', 'properties' => ['id' => $int, 'reassign_to' => $int + ['description' => 'Optional user ID to reassign their content to.']], 'required' => ['id']],
            ],

            // -- settings ---------------------------------------------------------
            [
                'category' => 'settings',
                'name' => 'get_option_value',
                'description' => 'Read a value from the WordPress options table (wp_options).',
                'inputSchema' => ['type' => 'object', 'properties' => ['name' => $string], 'required' => ['name']],
            ],
            [
                'category' => 'settings',
                'name' => 'update_option_value',
                'description' => 'Write a value to the WordPress options table (wp_options). Scalars and JSON-serializable values are supported.',
                'inputSchema' => ['type' => 'object', 'properties' => ['name' => $string, 'value' => ['description' => 'Any JSON value: string, number, boolean, object, or array.']], 'required' => ['name', 'value']],
            ],
            [
                'category' => 'settings',
                'name' => 'switch_theme',
                'description' => 'Switch the site\'s active theme.',
                'inputSchema' => ['type' => 'object', 'properties' => ['stylesheet' => $string + ['description' => 'The theme\'s folder/slug, as returned by list_themes.']], 'required' => ['stylesheet']],
            ],
            [
                'category' => 'settings',
                'name' => 'flush_rewrite_rules',
                'description' => 'Regenerate WordPress permalink rewrite rules.',
                'inputSchema' => $none,
            ],

            // -- admin_access -----------------------------------------------------
            [
                'category' => 'admin_access',
                'name' => 'create_admin_login_link',
                'description' => 'Create a one-time, short-lived link that signs a browser in as the connected WordPress admin without a password — useful for browser-automation tools that need to inspect or operate wp-admin. Returns a header-authenticated exchange endpoint, not a clickable URL: the real secret never appears in a navigable link, only a short-lived nonce does once you exchange it.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'expires_in' => $int + ['description' => 'Seconds the exchange token stays valid. 30-600, default 300.'],
                        'admin_path' => $string + ['description' => 'Optional wp-admin-relative path to open after login, e.g. "plugins.php". No external URLs.'],
                    ],
                ],
            ],

            // -- diagnostics (read-only, always available) ------------------------
            [
                'category' => 'diagnostics',
                'name' => 'get_site_info',
                'description' => 'Return basic site info: URL, WP version, PHP version, active theme, environment type, and which MCP Bridge tool categories are enabled.',
                'inputSchema' => $none,
            ],
            [
                'category' => 'diagnostics',
                'name' => 'list_themes',
                'description' => 'List installed themes and which one is active.',
                'inputSchema' => $none,
            ],
            [
                'category' => 'diagnostics',
                'name' => 'get_error_log',
                'description' => 'Return the last lines of wp-content/debug.log, if WP_DEBUG_LOG is enabled.',
                'inputSchema' => ['type' => 'object', 'properties' => ['lines' => $int + ['description' => 'Defaults to 100, max 1000.']]],
            ],
        ];
    }

    public static function definitions(): array
    {
        $out = [];
        foreach (self::tool_specs() as $spec) {
            if (!self::is_enabled($spec['category'])) {
                continue;
            }
            unset($spec['category']);
            $out[] = $spec;
        }
        return $out;
    }

    public static function call(string $name, array $args): array
    {
        try {
            $spec = null;
            foreach (self::tool_specs() as $candidate) {
                if ($candidate['name'] === $name) {
                    $spec = $candidate;
                    break;
                }
            }

            if (!$spec) {
                throw new \RuntimeException("Unknown tool: {$name}");
            }

            if (!self::is_enabled($spec['category'])) {
                throw new \RuntimeException("The \"{$spec['category']}\" tool category is disabled. Enable it on the MCP Bridge settings page first.");
            }

            $result = self::dispatch($name, $args);

            return ['content' => [['type' => 'text', 'text' => is_string($result) ? $result : wp_json_encode($result, JSON_PRETTY_PRINT)]], 'isError' => false];
        } catch (\Throwable $e) {
            return ['content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]], 'isError' => true];
        }
    }

    private static function dispatch(string $name, array $args): mixed
    {
        return match ($name) {
            'execute_php' => self::execute_php((string) ($args['code'] ?? '')),
            'run_sql' => self::run_sql((string) ($args['query'] ?? '')),
            'run_wp_cli' => self::run_wp_cli((array) ($args['args'] ?? []), (int) ($args['timeout'] ?? 60)),

            'read_file' => self::read_file((string) ($args['path'] ?? '')),
            'write_file' => self::write_file((string) ($args['path'] ?? ''), (string) ($args['content'] ?? '')),
            'edit_file' => self::edit_file((string) ($args['path'] ?? ''), (string) ($args['old_string'] ?? ''), (string) ($args['new_string'] ?? ''), (bool) ($args['replace_all'] ?? false)),
            'delete_file' => self::delete_file((string) ($args['path'] ?? '')),
            'move_file' => self::move_file((string) ($args['from'] ?? ''), (string) ($args['to'] ?? '')),
            'list_directory' => self::list_directory((string) ($args['path'] ?? '')),
            'create_upload_link' => self::create_upload_link($args),

            'list_plugins' => self::list_plugins(),
            'set_plugin_active' => self::set_plugin_active((string) ($args['plugin'] ?? ''), (bool) ($args['active'] ?? false)),

            'list_posts' => self::list_posts($args),
            'get_post' => self::get_post((int) ($args['id'] ?? 0)),
            'create_post' => self::create_post($args),
            'update_post' => self::update_post($args),
            'delete_post' => self::delete_post((int) ($args['id'] ?? 0), (bool) ($args['force'] ?? false)),
            'search_content' => self::search_content($args),
            'list_terms' => self::list_terms($args),
            'create_term' => self::create_term($args),
            'delete_term' => self::delete_term((string) ($args['taxonomy'] ?? 'category'), (int) ($args['term_id'] ?? 0)),

            'list_media' => self::list_media($args),
            'upload_media' => self::upload_media((string) ($args['filename'] ?? ''), (string) ($args['content_base64'] ?? '')),
            'delete_media' => self::delete_media((int) ($args['id'] ?? 0)),

            'list_comments' => self::list_comments($args),
            'moderate_comment' => self::moderate_comment((int) ($args['id'] ?? 0), (string) ($args['action'] ?? '')),

            'list_users' => self::list_users($args),
            'create_user' => self::create_user($args),
            'update_user' => self::update_user($args),
            'delete_user' => self::delete_user((int) ($args['id'] ?? 0), isset($args['reassign_to']) ? (int) $args['reassign_to'] : null),

            'get_option_value' => self::get_option_value((string) ($args['name'] ?? '')),
            'update_option_value' => self::update_option_value((string) ($args['name'] ?? ''), $args['value'] ?? null),
            'switch_theme' => self::switch_theme((string) ($args['stylesheet'] ?? '')),
            'flush_rewrite_rules' => self::do_flush_rewrite_rules(),

            'create_admin_login_link' => self::create_admin_login_link($args),

            'get_site_info' => self::get_site_info(),
            'list_themes' => self::list_themes(),
            'get_error_log' => self::get_error_log((int) ($args['lines'] ?? 100)),

            default => throw new \RuntimeException("Unknown tool: {$name}"),
        };
    }

    private static function clamp_per_page(mixed $value, int $default = 20, int $max = 100): int
    {
        $n = (int) ($value ?: $default);
        return max(1, min($max, $n));
    }

    // ---------------------------------------------------------------- php --

    private static function resolve_path(string $path): string
    {
        $root = rtrim((string) get_option('mcpb_fs_root', ABSPATH), '/');

        if ($path === '' || $path === '.') {
            return $root;
        }

        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path)
            ? $path
            : $root . '/' . ltrim($path, '/');
    }

    private static function execute_php(string $code): mixed
    {
        ob_start();
        try {
            $return_value = eval($code);
        } catch (\Throwable $e) {
            $output = ob_get_clean();
            throw new \RuntimeException(($output ? "Output before error:\n{$output}\n\n" : '') . 'PHP error: ' . $e->getMessage());
        }
        $output = ob_get_clean();

        $parts = [];
        if ($output !== '') {
            $parts[] = "Output:\n{$output}";
        }
        if ($return_value !== null) {
            $parts[] = 'Return value: ' . (is_scalar($return_value) ? (string) $return_value : wp_json_encode($return_value));
        }

        return $parts ? implode("\n\n", $parts) : '(no output, no return value)';
    }

    private static function run_wp_cli(array $raw_args, int $timeout): array
    {
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('proc_open is disabled in PHP configuration; WP-CLI cannot be run.');
        }

        $args = [];
        foreach ($raw_args as $arg) {
            if (!is_string($arg)) {
                throw new \RuntimeException('args must be an array of strings.');
            }
            $args[] = $arg;
        }
        if (!$args) {
            throw new \RuntimeException('args must not be empty.');
        }

        $wp_command = self::find_wp_cli_command();
        if ($wp_command === null) {
            throw new \RuntimeException('WP-CLI was not found on this server (checked a bundled wp-cli.phar, "which wp"/"command -v wp", and common install paths). Define the MCPB_WP_CLI_COMMAND constant in wp-config.php with the full invocation, e.g. ["/usr/local/bin/php", "/path/to/wp-cli.phar"].');
        }

        $timeout = max(1, min(300, $timeout ?: 60));

        // Array-form proc_open: PHP hands each element straight to the OS as
        // its own argv entry, so ability input can never reach a shell as
        // syntax. Never build a shell string out of these arguments.
        $cmd = array_merge($wp_command, $args);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = proc_open($cmd, $descriptors, $pipes, ABSPATH);
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start WP-CLI process.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $max_bytes = 200 * 1024;
        $start = time();
        $timed_out = false;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (time() - $start > $timeout) {
                proc_terminate($process);
                $timed_out = true;
                break;
            }
            usleep(100_000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);

        return [
            'success' => !$timed_out && $exit_code === 0,
            'exit_code' => $timed_out ? null : $exit_code,
            'stdout' => self::truncate_output($stdout, $max_bytes),
            'stderr' => self::truncate_output($stderr, $max_bytes) . ($timed_out ? "\n[Killed: exceeded {$timeout}s timeout]" : ''),
        ];
    }

    private static function truncate_output(string $text, int $max_bytes): string
    {
        if (strlen($text) <= $max_bytes) {
            return $text;
        }
        return substr($text, 0, $max_bytes) . "\n...[truncated]";
    }

    /**
     * Resolve the WP-CLI invocation for this server, as an argv list.
     *
     * @return list<string>|null
     */
    private static function find_wp_cli_command(): ?array
    {
        if (defined('MCPB_WP_CLI_COMMAND')) {
            $configured = constant('MCPB_WP_CLI_COMMAND');
            $normalized = is_array($configured) ? $configured : [(string) $configured];
            $normalized = array_values(array_filter($normalized, fn ($v) => is_string($v) && $v !== ''));
            if ($normalized) {
                return $normalized;
            }
        }

        $phar = ABSPATH . 'wp-cli.phar';
        if (is_file($phar)) {
            return [PHP_BINARY, $phar];
        }

        if (function_exists('exec')) {
            $output = [];
            $rc = 0;
            exec('command -v wp 2>/dev/null', $output, $rc);
            $first = trim((string) ($output[0] ?? ''));
            if ($rc === 0 && $first !== '') {
                return [$first];
            }
        }

        foreach (['/usr/local/bin/wp', '/usr/bin/wp', '/bin/wp'] as $path) {
            if (is_file($path) && is_executable($path)) {
                return [$path];
            }
        }

        return null;
    }

    // ---------------------------------------------------------- database --

    private static function run_sql(string $query): mixed
    {
        global $wpdb;
        $trimmed = ltrim($query);
        $is_select = stripos($trimmed, 'select') === 0 || stripos($trimmed, 'show') === 0 || stripos($trimmed, 'describe') === 0;

        if ($is_select) {
            $rows = $wpdb->get_results($query, ARRAY_A);
            if ($wpdb->last_error) {
                throw new \RuntimeException($wpdb->last_error);
            }
            return $rows;
        }

        $affected = $wpdb->query($query);
        if ($wpdb->last_error) {
            throw new \RuntimeException($wpdb->last_error);
        }
        return "Query OK, {$affected} row(s) affected.";
    }

    // -------------------------------------------------------- filesystem --

    private static function read_file(string $path): string
    {
        $full = self::resolve_path($path);
        if (!is_file($full) || !is_readable($full)) {
            throw new \RuntimeException("File not readable: {$full}");
        }
        if (filesize($full) > 2 * 1024 * 1024) {
            throw new \RuntimeException('File larger than 2MB; refusing to read in full.');
        }
        return (string) file_get_contents($full);
    }

    private static function write_file(string $path, string $content): string
    {
        $full = self::resolve_path($path);
        $dir = dirname($full);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            throw new \RuntimeException("Could not create directory: {$dir}");
        }
        if (file_put_contents($full, $content) === false) {
            throw new \RuntimeException("Failed to write file: {$full}");
        }
        return 'Wrote ' . strlen($content) . " bytes to {$full}";
    }

    private static function edit_file(string $path, string $old_string, string $new_string, bool $replace_all): array
    {
        $full = self::resolve_path($path);
        if (!is_file($full)) {
            throw new \RuntimeException("File not found: {$full}");
        }
        if ($old_string === $new_string) {
            throw new \RuntimeException('old_string and new_string are identical; no edit needed.');
        }

        $content = file_get_contents($full);
        if ($content === false) {
            throw new \RuntimeException("Could not read file: {$full}");
        }

        $count = substr_count($content, $old_string);
        if ($count === 0) {
            throw new \RuntimeException('old_string was not found in the file. Read the file first to match it exactly, including whitespace.');
        }
        if ($count > 1 && !$replace_all) {
            throw new \RuntimeException("old_string was found {$count} times. Include more surrounding context to make it unique, or set replace_all to true.");
        }

        $new_content = $replace_all
            ? str_replace($old_string, $new_string, $content)
            : self::replace_first($content, $old_string, $new_string);

        $bytes = file_put_contents($full, $new_content, LOCK_EX);
        if ($bytes === false) {
            throw new \RuntimeException("Failed to write file: {$full}");
        }

        return ['path' => $full, 'replacements' => $count, 'size' => $bytes];
    }

    private static function replace_first(string $content, string $old, string $new): string
    {
        $pos = strpos($content, $old);
        return substr($content, 0, $pos) . $new . substr($content, $pos + strlen($old));
    }

    private static function delete_file(string $path): string
    {
        $full = self::resolve_path($path);
        if (is_dir($full)) {
            if (!@rmdir($full)) {
                throw new \RuntimeException("Could not remove directory (must be empty): {$full}");
            }
        } elseif (is_file($full)) {
            if (!@unlink($full)) {
                throw new \RuntimeException("Could not delete file: {$full}");
            }
        } else {
            throw new \RuntimeException("Path does not exist: {$full}");
        }
        return "Deleted {$full}";
    }

    private static function move_file(string $from, string $to): string
    {
        $full_from = self::resolve_path($from);
        $full_to = self::resolve_path($to);

        if (!file_exists($full_from)) {
            throw new \RuntimeException("Source does not exist: {$full_from}");
        }

        $dir = dirname($full_to);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            throw new \RuntimeException("Could not create directory: {$dir}");
        }

        if (!@rename($full_from, $full_to)) {
            throw new \RuntimeException("Could not move {$full_from} to {$full_to}");
        }

        return "Moved {$full_from} to {$full_to}";
    }

    private static function list_directory(string $path): array
    {
        $full = self::resolve_path($path);
        if (!is_dir($full)) {
            throw new \RuntimeException("Not a directory: {$full}");
        }

        $entries = [];
        foreach (scandir($full) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $entry_path = $full . '/' . $entry;
            $entries[] = [
                'name' => $entry,
                'type' => is_dir($entry_path) ? 'directory' : 'file',
                'size' => is_file($entry_path) ? filesize($entry_path) : null,
            ];
        }

        return $entries;
    }

    private static function create_upload_link(array $args): array
    {
        $path = (string) ($args['path'] ?? '');
        if ($path === '') {
            throw new \RuntimeException('path is required');
        }
        $full = self::resolve_path($path);

        $expires_in = max(30, min(3600, (int) ($args['expires_in'] ?? 900)));
        $max_bytes = max(1, (int) ($args['max_bytes'] ?? 104_857_600));
        $overwrite = (bool) ($args['overwrite'] ?? false);
        $create_dirs = (bool) ($args['create_directories'] ?? true);

        if (!$overwrite && file_exists($full)) {
            throw new \RuntimeException("Destination already exists and overwrite is false: {$full}");
        }

        $meta = wp_json_encode([
            'path' => $full,
            'max_bytes' => $max_bytes,
            'overwrite' => $overwrite,
            'create_directories' => $create_dirs,
        ]);

        $token = Tokens::issue_single('upload', get_current_user_id(), (string) $meta, $expires_in);
        $upload_url = rest_url(MCPB_NAMESPACE . '/upload');

        return [
            'upload_url' => $upload_url,
            'upload_token' => $token,
            'token_header' => 'X-MCPB-Upload-Token',
            'method' => 'PUT',
            'path' => $full,
            'expires_in' => $expires_in,
            'max_bytes' => $max_bytes,
            'note' => 'One-time use. Send the raw file bytes as the request body, not JSON or multipart.',
            'curl_example' => sprintf('curl -X PUT --data-binary @local-file -H "X-MCPB-Upload-Token: %s" %s', $token, $upload_url),
        ];
    }

    // ----------------------------------------------------------- plugins --

    private static function list_plugins(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all = get_plugins();
        $active = get_option('active_plugins', []);
        $out = [];
        foreach ($all as $file => $data) {
            $out[] = [
                'plugin' => $file,
                'name' => $data['Name'],
                'version' => $data['Version'],
                'active' => in_array($file, $active, true),
            ];
        }
        return $out;
    }

    private static function set_plugin_active(string $plugin, bool $active): string
    {
        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if ($active) {
            $result = activate_plugin($plugin);
            if (is_wp_error($result)) {
                throw new \RuntimeException($result->get_error_message());
            }
            return "Activated {$plugin}";
        }

        deactivate_plugins([$plugin]);
        return "Deactivated {$plugin}";
    }

    // ------------------------------------------------------------ content --

    private static function list_posts(array $args): array
    {
        $posts = get_posts([
            'post_type' => (string) ($args['post_type'] ?? 'post'),
            'post_status' => (string) ($args['status'] ?? 'any'),
            's' => (string) ($args['search'] ?? ''),
            'posts_per_page' => self::clamp_per_page($args['per_page'] ?? null),
            'paged' => max(1, (int) ($args['page'] ?? 1)),
        ]);

        return array_map([self::class, 'summarize_post'], $posts);
    }

    private static function summarize_post(\WP_Post $post): array
    {
        return [
            'id' => $post->ID,
            'post_type' => $post->post_type,
            'title' => get_the_title($post),
            'status' => $post->post_status,
            'date' => $post->post_date,
            'excerpt' => wp_trim_words(wp_strip_all_tags($post->post_content), 30),
            'link' => get_permalink($post),
        ];
    }

    private static function get_post(int $id): array
    {
        $post = get_post($id, ARRAY_A);
        if (!$post) {
            throw new \RuntimeException("No post with ID {$id}");
        }

        $meta = get_post_meta($id);
        $post['meta'] = array_map(fn ($v) => count($v) === 1 ? $v[0] : $v, $meta);
        $post['link'] = get_permalink($id);

        return $post;
    }

    private static function create_post(array $args): array
    {
        $content = (string) ($args['content'] ?? '');

        $post_id = wp_insert_post([
            'post_type' => (string) ($args['post_type'] ?? 'post'),
            'post_title' => (string) ($args['title'] ?? ''),
            'post_content' => $content,
            'post_excerpt' => (string) ($args['excerpt'] ?? ''),
            'post_status' => (string) ($args['status'] ?? 'draft'),
        ], true);

        if (is_wp_error($post_id)) {
            throw new \RuntimeException($post_id->get_error_message());
        }

        self::apply_meta($post_id, $args['meta'] ?? null);

        return self::with_block_warnings(self::get_post($post_id), $content);
    }

    private static function update_post(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if (!$id || !get_post($id)) {
            throw new \RuntimeException("No post with ID {$id}");
        }

        $update = ['ID' => $id];
        foreach (['title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status'] as $arg_key => $field) {
            if (array_key_exists($arg_key, $args)) {
                $update[$field] = (string) $args[$arg_key];
            }
        }

        $result = wp_update_post($update, true);
        if (is_wp_error($result)) {
            throw new \RuntimeException($result->get_error_message());
        }

        self::apply_meta($id, $args['meta'] ?? null);

        return self::with_block_warnings(self::get_post($id), (string) ($args['content'] ?? ''));
    }

    /**
     * Flags block markup that WordPress can't validate server-side (usually
     * a third-party block whose save() only exists in browser JS) instead of
     * silently writing it as-is. Not a substitute for rendering it through a
     * real block editor, just an honest heads-up.
     */
    private static function with_block_warnings(array $result, string $content): array
    {
        $warnings = self::block_warnings($content);
        if ($warnings) {
            $result['warnings'] = $warnings;
        }
        return $result;
    }

    private static function block_warnings(string $content): array
    {
        if (!str_contains($content, '<!-- wp:') || !function_exists('parse_blocks') || !class_exists('WP_Block_Type_Registry')) {
            return [];
        }

        $registry = \WP_Block_Type_Registry::get_instance();
        $unregistered = [];
        self::collect_unregistered_blocks(parse_blocks($content), $registry, $unregistered);

        return array_map(
            fn ($name) => "Block \"{$name}\" is not registered server-side (likely a third-party block whose save() only exists in browser JS); its markup was written as-is and was not validated.",
            $unregistered
        );
    }

    private static function collect_unregistered_blocks(array $blocks, \WP_Block_Type_Registry $registry, array &$unregistered): void
    {
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? null;
            if ($name && !$registry->is_registered($name) && !in_array($name, $unregistered, true)) {
                $unregistered[] = $name;
            }
            if (!empty($block['innerBlocks'])) {
                self::collect_unregistered_blocks($block['innerBlocks'], $registry, $unregistered);
            }
        }
    }

    private static function apply_meta(int $post_id, mixed $meta): void
    {
        if (!is_array($meta)) {
            return;
        }
        foreach ($meta as $key => $value) {
            update_post_meta($post_id, (string) $key, $value);
        }
    }

    private static function delete_post(int $id, bool $force): string
    {
        $result = wp_delete_post($id, $force);
        if (!$result) {
            throw new \RuntimeException("Could not delete post {$id}");
        }
        return $force ? "Permanently deleted post {$id}" : "Moved post {$id} to trash";
    }

    private static function search_content(array $args): array
    {
        $query = new \WP_Query([
            's' => (string) ($args['query'] ?? ''),
            'post_type' => (string) ($args['post_type'] ?? 'any'),
            'posts_per_page' => self::clamp_per_page($args['per_page'] ?? null),
        ]);

        return array_map([self::class, 'summarize_post'], $query->posts);
    }

    private static function list_terms(array $args): array
    {
        $terms = get_terms([
            'taxonomy' => (string) ($args['taxonomy'] ?? 'category'),
            'hide_empty' => false,
            'search' => (string) ($args['search'] ?? ''),
            'number' => self::clamp_per_page($args['per_page'] ?? null),
        ]);

        if (is_wp_error($terms)) {
            throw new \RuntimeException($terms->get_error_message());
        }

        return array_map(fn ($t) => ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count, 'parent' => $t->parent], $terms);
    }

    private static function create_term(array $args): array
    {
        $result = wp_insert_term(
            (string) ($args['name'] ?? ''),
            (string) ($args['taxonomy'] ?? 'category'),
            ['parent' => (int) ($args['parent'] ?? 0)]
        );

        if (is_wp_error($result)) {
            throw new \RuntimeException($result->get_error_message());
        }

        return $result;
    }

    private static function delete_term(string $taxonomy, int $term_id): string
    {
        $result = wp_delete_term($term_id, $taxonomy);
        if (is_wp_error($result)) {
            throw new \RuntimeException($result->get_error_message());
        }
        if (!$result) {
            throw new \RuntimeException("Could not delete term {$term_id}");
        }
        return "Deleted term {$term_id} from {$taxonomy}";
    }

    // -------------------------------------------------------------- media --

    private static function list_media(array $args): array
    {
        $items = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => self::clamp_per_page($args['per_page'] ?? null),
            'paged' => max(1, (int) ($args['page'] ?? 1)),
        ]);

        return array_map(fn ($p) => [
            'id' => $p->ID,
            'title' => get_the_title($p),
            'mime_type' => $p->post_mime_type,
            'url' => wp_get_attachment_url($p->ID),
            'date' => $p->post_date,
        ], $items);
    }

    private static function upload_media(string $filename, string $content_base64): array
    {
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $decoded = base64_decode($content_base64, true);
        if ($decoded === false) {
            throw new \RuntimeException('content_base64 is not valid base64.');
        }

        $upload = wp_upload_bits($filename, null, $decoded);
        if (!empty($upload['error'])) {
            throw new \RuntimeException($upload['error']);
        }

        $filetype = wp_check_filetype($upload['file'], null);
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $filetype['type'],
            'post_title' => sanitize_file_name($filename),
            'post_status' => 'inherit',
        ], $upload['file']);

        if (is_wp_error($attachment_id)) {
            throw new \RuntimeException($attachment_id->get_error_message());
        }

        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $upload['file']));

        return ['id' => $attachment_id, 'url' => wp_get_attachment_url($attachment_id)];
    }

    private static function delete_media(int $id): string
    {
        $result = wp_delete_attachment($id, true);
        if (!$result) {
            throw new \RuntimeException("Could not delete media {$id}");
        }
        return "Deleted media {$id}";
    }

    // ---------------------------------------------------------- comments --

    private static function list_comments(array $args): array
    {
        $comments = get_comments([
            'post_id' => (int) ($args['post_id'] ?? 0) ?: '',
            'status' => (string) ($args['status'] ?? 'all'),
            'number' => self::clamp_per_page($args['per_page'] ?? null),
        ]);

        return array_map(fn ($c) => [
            'id' => (int) $c->comment_ID,
            'post_id' => (int) $c->comment_post_ID,
            'author' => $c->comment_author,
            'author_email' => $c->comment_author_email,
            'content' => $c->comment_content,
            'status' => wp_get_comment_status($c),
            'date' => $c->comment_date,
        ], $comments);
    }

    private static function moderate_comment(int $id, string $action): string
    {
        if ($action === 'delete') {
            if (!wp_delete_comment($id, true)) {
                throw new \RuntimeException("Could not delete comment {$id}");
            }
            return "Deleted comment {$id}";
        }

        if (!in_array($action, ['approve', 'spam', 'trash'], true)) {
            throw new \RuntimeException('action must be one of: approve, spam, trash, delete');
        }

        if (!wp_set_comment_status($id, $action)) {
            throw new \RuntimeException("Could not set comment {$id} to {$action}");
        }

        return "Comment {$id} set to {$action}";
    }

    // -------------------------------------------------------------- users --

    private static function list_users(array $args): array
    {
        $users = get_users([
            'role' => (string) ($args['role'] ?? ''),
            'number' => self::clamp_per_page($args['per_page'] ?? null),
        ]);

        return array_map(fn ($u) => [
            'id' => $u->ID,
            'username' => $u->user_login,
            'email' => $u->user_email,
            'display_name' => $u->display_name,
            'roles' => $u->roles,
        ], $users);
    }

    private static function create_user(array $args): array
    {
        $user_id = wp_insert_user([
            'user_login' => (string) ($args['username'] ?? ''),
            'user_email' => (string) ($args['email'] ?? ''),
            'user_pass' => (string) ($args['password'] ?? ''),
            'role' => (string) ($args['role'] ?? 'subscriber'),
        ]);

        if (is_wp_error($user_id)) {
            throw new \RuntimeException($user_id->get_error_message());
        }

        return ['id' => $user_id, 'username' => $args['username']];
    }

    private static function update_user(array $args): array
    {
        $update = ['ID' => (int) ($args['id'] ?? 0)];
        foreach (['email' => 'user_email', 'password' => 'user_pass', 'role' => 'role', 'display_name' => 'display_name'] as $arg_key => $field) {
            if (array_key_exists($arg_key, $args)) {
                $update[$field] = $args[$arg_key];
            }
        }

        $result = wp_update_user($update);
        if (is_wp_error($result)) {
            throw new \RuntimeException($result->get_error_message());
        }

        return ['id' => $result];
    }

    private static function delete_user(int $id, ?int $reassign): string
    {
        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        if (!wp_delete_user($id, $reassign)) {
            throw new \RuntimeException("Could not delete user {$id}");
        }

        return "Deleted user {$id}";
    }

    // ----------------------------------------------------------- settings --

    private static function get_option_value(string $name): mixed
    {
        if ($name === '') {
            throw new \RuntimeException('name is required');
        }
        return ['name' => $name, 'value' => get_option($name)];
    }

    private static function update_option_value(string $name, mixed $value): string
    {
        if ($name === '') {
            throw new \RuntimeException('name is required');
        }
        update_option($name, $value);
        return "Updated option \"{$name}\"";
    }

    private static function switch_theme(string $stylesheet): string
    {
        if (!wp_get_theme($stylesheet)->exists()) {
            throw new \RuntimeException("No theme found with stylesheet \"{$stylesheet}\"");
        }
        switch_theme($stylesheet);
        return "Switched active theme to {$stylesheet}";
    }

    private static function do_flush_rewrite_rules(): string
    {
        flush_rewrite_rules(false);
        return 'Rewrite rules flushed.';
    }

    // ------------------------------------------------------- admin_access --

    private static function create_admin_login_link(array $args): array
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            throw new \RuntimeException('No authenticated user context.');
        }

        $expires_in = max(30, min(600, (int) ($args['expires_in'] ?? 300)));
        $admin_path = ltrim((string) ($args['admin_path'] ?? ''), '/');
        if (str_contains($admin_path, '://')) {
            throw new \RuntimeException('admin_path must be a wp-admin-relative path, not a full URL.');
        }

        $meta = wp_json_encode(['redirect' => $admin_path]);
        $token = Tokens::issue_single('admin_login', $user_id, (string) $meta, $expires_in);
        $exchange_url = rest_url(MCPB_NAMESPACE . '/admin-login/exchange');

        return [
            'exchange_url' => $exchange_url,
            'exchange_method' => 'POST',
            'access_token' => $token,
            'token_header' => 'X-MCPB-Admin-Access-Token',
            'expires_in' => $expires_in,
            'one_time' => true,
            'note' => 'This is not a clickable link. POST to exchange_url with access_token in the token_header to receive a one-time login_url, then open that URL in a browser within about a minute. The real secret is never placed in a navigable URL.',
            'curl_example' => sprintf('curl -s -X POST -H "X-MCPB-Admin-Access-Token: %s" %s', $token, $exchange_url),
        ];
    }

    // -------------------------------------------------------- diagnostics --

    private static function get_site_info(): array
    {
        global $wp_version;
        return [
            'site_url' => site_url(),
            'home_url' => home_url(),
            'wp_version' => $wp_version ?? null,
            'php_version' => PHP_VERSION,
            'active_theme' => wp_get_theme()->get('Name'),
            'environment_type' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'unknown',
            'fs_root' => get_option('mcpb_fs_root', ABSPATH),
            'skills_dir' => Skills::directory(),
            'enabled_tool_categories' => array_keys(array_filter(self::enabled_categories())),
        ];
    }

    private static function list_themes(): array
    {
        $current = get_option('stylesheet');
        $out = [];
        foreach (wp_get_themes() as $stylesheet => $theme) {
            $out[] = [
                'stylesheet' => $stylesheet,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'active' => $stylesheet === $current,
            ];
        }
        return $out;
    }

    private static function get_error_log(int $lines): string
    {
        $path = WP_CONTENT_DIR . '/debug.log';
        if (!is_file($path)) {
            return 'No debug.log found. Enable WP_DEBUG_LOG in wp-config.php to start logging.';
        }

        $lines = max(1, min(1000, $lines ?: 100));
        $content = file_get_contents($path, false, null, max(0, filesize($path) - 2 * 1024 * 1024));
        $all_lines = explode("\n", (string) $content);

        return implode("\n", array_slice($all_lines, -$lines));
    }
}
