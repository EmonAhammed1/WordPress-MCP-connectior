<?php

declare(strict_types=1);

namespace MCPBridge;

if (!defined('ABSPATH')) {
    exit;
}

class Rest_Routes
{
    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register']);
    }

    public static function register(): void
    {
        register_rest_route(MCPB_NAMESPACE, MCPB_MCP_ROUTE, [
            [
                'methods' => 'POST',
                'callback' => [self::class, 'mcp_endpoint'],
                'permission_callback' => '__return_true',
            ],
            [
                // We don't support server-initiated SSE streams or explicit
                // session teardown; 405 tells spec-compliant clients that's
                // fine and to keep using POST for everything.
                'methods' => ['GET', 'DELETE'],
                'callback' => fn () => new \WP_REST_Response(['error' => 'Method not supported by this server'], 405),
                'permission_callback' => '__return_true',
            ],
        ]);

        register_rest_route(MCPB_NAMESPACE, '/register', [
            'methods' => 'POST',
            'callback' => [OAuth_Server::class, 'register_client'],
            'permission_callback' => '__return_true',
        ]);

        // NOTE: /authorize is intentionally NOT registered here. It's a
        // browser + cookie-auth page handled on the 'init' hook instead —
        // see OAuth_Server::maybe_handle_authorize(). Registering it as a
        // REST route would make WordPress demand an X-WP-Nonce header that
        // a plain browser login redirect can never provide.

        register_rest_route(MCPB_NAMESPACE, '/token', [
            'methods' => 'POST',
            'callback' => [OAuth_Server::class, 'token'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(MCPB_NAMESPACE, '/revoke', [
            'methods' => 'POST',
            'callback' => [OAuth_Server::class, 'revoke'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(MCPB_NAMESPACE, '/upload', [
            'methods' => ['PUT', 'POST'],
            'callback' => [self::class, 'upload_endpoint'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(MCPB_NAMESPACE, '/admin-login/exchange', [
            'methods' => 'POST',
            'callback' => [Magic_Login::class, 'exchange'],
            'permission_callback' => '__return_true',
        ]);

        // NOTE: GET /admin-login/<nonce> is intentionally NOT registered
        // here, for the same reason as /authorize — see Magic_Login::init().
    }

    public static function upload_endpoint(\WP_REST_Request $request)
    {
        $token = $request->get_header('x-mcpb-upload-token') ?: '';
        if (!$token) {
            return new \WP_REST_Response(['error' => 'Missing X-MCPB-Upload-Token header'], 401);
        }

        $row = Tokens::get_token($token, 'upload');
        if (!$row) {
            return new \WP_REST_Response(['error' => 'Invalid or expired upload token'], 401);
        }

        if (!Tools::category_enabled('filesystem')) {
            return new \WP_REST_Response(['error' => 'The "filesystem" tool category has been disabled since this link was created'], 403);
        }

        $meta = json_decode((string) $row['scope'], true) ?: [];
        $path = (string) ($meta['path'] ?? '');
        $max_bytes = (int) ($meta['max_bytes'] ?? 0);
        $overwrite = !empty($meta['overwrite']);
        $create_dirs = !empty($meta['create_directories']);

        if ($path === '') {
            return new \WP_REST_Response(['error' => 'Upload token has no destination path'], 400);
        }

        $body = $request->get_body();
        if (strlen($body) > $max_bytes) {
            return new \WP_REST_Response(['error' => "Upload exceeds max_bytes ({$max_bytes})"], 413);
        }

        if (!$overwrite && file_exists($path)) {
            return new \WP_REST_Response(['error' => 'Destination already exists'], 409);
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!$create_dirs || !wp_mkdir_p($dir)) {
                return new \WP_REST_Response(['error' => "Could not create directory: {$dir}"], 500);
            }
        }

        if (file_put_contents($path, $body) === false) {
            return new \WP_REST_Response(['error' => "Failed to write file: {$path}"], 500);
        }

        Tokens::revoke_by_raw($token);

        return new \WP_REST_Response(['success' => true, 'path' => $path, 'bytes_written' => strlen($body)], 200);
    }

    public static function mcp_endpoint(\WP_REST_Request $request)
    {
        $auth = OAuth_Server::authenticate_request($request);

        if (is_wp_error($auth)) {
            $response = new \WP_REST_Response(['error' => $auth->get_error_message()], 401);
            $response->header(
                'WWW-Authenticate',
                sprintf('Bearer resource_metadata="%s"', home_url('/.well-known/oauth-protected-resource'))
            );
            return $response;
        }

        wp_set_current_user((int) $auth['user_id']);

        return MCP_Server::handle($request);
    }
}
