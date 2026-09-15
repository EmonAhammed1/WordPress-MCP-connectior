<?php

declare(strict_types=1);

namespace MCPBridge;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Minimal OAuth 2.1 authorization server: dynamic client registration
 * (RFC 7591), authorization code + PKCE, refresh tokens, revocation.
 * Opaque bearer tokens only — no JWT dependency needed.
 */
class OAuth_Server
{
    public static function init(): void
    {
        // /authorize is a browser + cookie-auth page. It must NOT go through
        // register_rest_route(): WordPress's REST cookie-auth layer requires
        // an X-WP-Nonce header on every cookie-authenticated request, which a
        // plain browser redirect/login can never send, so the request would
        // be rejected before reaching our callback. Handle it directly on
        // 'init' instead, the same way Well_Known serves the discovery docs.
        add_action('init', [self::class, 'maybe_handle_authorize'], 5);
    }

    public static function authorize_url(): string
    {
        return rest_url(MCPB_NAMESPACE . '/authorize');
    }

    public static function maybe_handle_authorize(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $target_path = parse_url(self::authorize_url(), PHP_URL_PATH);

        $is_match = is_string($path) && is_string($target_path) && rtrim($path, '/') === rtrim($target_path, '/');

        // Fallback for sites without pretty permalinks (index.php?rest_route=/...).
        if (!$is_match) {
            $rest_route = $_GET['rest_route'] ?? '';
            $is_match = is_string($rest_route) && rtrim($rest_route, '/') === '/' . MCPB_NAMESPACE . '/authorize';
        }

        if (!$is_match) {
            return;
        }

        self::authorize();
    }

    public static function register_client(\WP_REST_Request $request)
    {
        $body = json_decode($request->get_body(), true) ?: [];

        $redirect_uris = $body['redirect_uris'] ?? [];
        if (!is_array($redirect_uris) || empty($redirect_uris)) {
            return new \WP_Error('invalid_client_metadata', 'redirect_uris is required', ['status' => 400]);
        }

        foreach ($redirect_uris as $uri) {
            if (!filter_var($uri, FILTER_VALIDATE_URL)) {
                return new \WP_Error('invalid_redirect_uri', 'One or more redirect_uris are invalid', ['status' => 400]);
            }
        }

        $auth_method = $body['token_endpoint_auth_method'] ?? 'none';
        $name = sanitize_text_field($body['client_name'] ?? 'MCP Client');

        $secret = null;
        if ($auth_method !== 'none') {
            $secret = Tokens::random('secret', 32);
        }

        $client_id = Tokens::register_client($name, $redirect_uris, $auth_method, $secret);

        $response = [
            'client_id' => $client_id,
            'client_name' => $name,
            'redirect_uris' => array_values($redirect_uris),
            'token_endpoint_auth_method' => $auth_method,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ];

        if ($secret) {
            $response['client_secret'] = $secret;
        }

        return new \WP_REST_Response($response, 201);
    }

    private static function authorize(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $source = wp_unslash($method === 'POST' ? $_POST : $_GET);

        $client_id = sanitize_text_field((string) ($source['client_id'] ?? ''));
        $redirect_uri = (string) ($source['redirect_uri'] ?? '');
        $state = (string) ($source['state'] ?? '');
        $scope = sanitize_text_field((string) ($source['scope'] ?? 'mcp'));
        $challenge = isset($source['code_challenge']) ? (string) $source['code_challenge'] : '';
        $challenge_method = isset($source['code_challenge_method']) ? (string) $source['code_challenge_method'] : 'S256';

        $client = Tokens::get_client($client_id);
        if (!$client) {
            self::html_error('Unknown client', 'This app is not registered with this site.');
        }

        if (!in_array($redirect_uri, $client['redirect_uris'], true)) {
            self::html_error('Invalid redirect_uri', 'The redirect URI does not match what was registered.');
        }

        if (!is_user_logged_in()) {
            $return_to = add_query_arg($source, self::authorize_url());
            wp_redirect(wp_login_url($return_to));
            exit;
        }

        if (!current_user_can('manage_options')) {
            self::html_error('Not allowed', 'Only administrators can approve MCP connector access.');
        }

        if ($method === 'POST') {
            check_admin_referer('mcpb_authorize');

            if (($source['mcpb_action'] ?? '') !== 'approve') {
                $denied = add_query_arg(['error' => 'access_denied', 'state' => $state], $redirect_uri);
                wp_redirect($denied);
                exit;
            }

            $code = Tokens::create_auth_code(
                $client_id,
                get_current_user_id(),
                $redirect_uri,
                $challenge !== '' ? $challenge : null,
                $challenge_method !== '' ? $challenge_method : null,
                $scope
            );

            $target = add_query_arg(array_filter(['code' => $code, 'state' => $state]), $redirect_uri);
            wp_redirect($target);
            exit;
        }

        self::render_consent_page($client, $redirect_uri, $state, $scope, $challenge, $challenge_method);
    }

    private static function render_consent_page(array $client, string $redirect_uri, string $state, string $scope, string $challenge, string $challenge_method): void
    {
        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        $site = get_bloginfo('name');
        ?>
        <!doctype html>
        <html>
        <head>
        <meta charset="utf-8">
        <title>Connect Claude &mdash; <?php echo esc_html($site); ?></title>
        <style>
            body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f5f5f4;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
            .card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:32px;max-width:420px;width:90%}
            h1{font-size:18px;margin:0 0 8px}
            p{color:#555;font-size:14px;line-height:1.5}
            .scope{background:#f5f5f4;border-radius:8px;padding:12px 16px;font-size:13px;margin:16px 0}
            .row{display:flex;gap:12px;margin-top:20px}
            button{flex:1;padding:10px 16px;border-radius:8px;border:1px solid #ddd;font-size:14px;cursor:pointer}
            .approve{background:#d97757;color:#fff;border-color:#d97757}
            .deny{background:#fff;color:#333}
        </style>
        </head>
        <body>
        <div class="card">
            <h1><?php echo esc_html($client['client_name'] ?: 'MCP Client'); ?> wants to connect</h1>
            <p>This will let the app read and act on <strong><?php echo esc_html($site); ?></strong>, including running PHP, files, the database and plugins, depending on what you enable in MCP Bridge settings.</p>
            <div class="scope">Scope: <?php echo esc_html($scope); ?></div>
            <form method="post">
                <?php wp_nonce_field('mcpb_authorize'); ?>
                <input type="hidden" name="client_id" value="<?php echo esc_attr($client['client_id']); ?>">
                <input type="hidden" name="redirect_uri" value="<?php echo esc_attr($redirect_uri); ?>">
                <input type="hidden" name="state" value="<?php echo esc_attr($state); ?>">
                <input type="hidden" name="scope" value="<?php echo esc_attr($scope); ?>">
                <input type="hidden" name="code_challenge" value="<?php echo esc_attr($challenge); ?>">
                <input type="hidden" name="code_challenge_method" value="<?php echo esc_attr($challenge_method); ?>">
                <div class="row">
                    <button class="deny" type="submit" name="mcpb_action" value="deny">Deny</button>
                    <button class="approve" type="submit" name="mcpb_action" value="approve">Allow</button>
                </div>
            </form>
        </div>
        </body>
        </html>
        <?php
        exit;
    }

    private static function html_error(string $title, string $message): void
    {
        status_header(400);
        header('Content-Type: text/html; charset=utf-8');
        printf('<h1>%s</h1><p>%s</p>', esc_html($title), esc_html($message));
        exit;
    }

    public static function token(\WP_REST_Request $request)
    {
        $params = $request->get_body_params();
        if (empty($params)) {
            parse_str($request->get_body(), $params);
        }

        $grant_type = $params['grant_type'] ?? '';

        if ($grant_type === 'authorization_code') {
            return self::grant_authorization_code($params);
        }

        if ($grant_type === 'refresh_token') {
            return self::grant_refresh_token($params);
        }

        return new \WP_Error('unsupported_grant_type', 'Unsupported grant_type', ['status' => 400]);
    }

    private static function grant_authorization_code(array $params)
    {
        $code = $params['code'] ?? '';
        $verifier = $params['code_verifier'] ?? '';
        $client_id = $params['client_id'] ?? '';
        $redirect_uri = $params['redirect_uri'] ?? '';

        $row = Tokens::consume_auth_code((string) $code);
        if (!$row) {
            return new \WP_Error('invalid_grant', 'Authorization code is invalid or expired', ['status' => 400]);
        }

        if ($client_id && $row['client_id'] !== $client_id) {
            return new \WP_Error('invalid_grant', 'client_id mismatch', ['status' => 400]);
        }

        if ($redirect_uri && $row['redirect_uri'] !== $redirect_uri) {
            return new \WP_Error('invalid_grant', 'redirect_uri mismatch', ['status' => 400]);
        }

        if (!empty($row['code_challenge'])) {
            if (!$verifier || !self::pkce_matches((string) $verifier, $row['code_challenge'], $row['code_challenge_method'] ?: 'S256')) {
                return new \WP_Error('invalid_grant', 'PKCE verification failed', ['status' => 400]);
            }
        }

        $pair = Tokens::issue_token_pair($row['client_id'], (int) $row['user_id'], $row['scope']);

        return new \WP_REST_Response([
            'access_token' => $pair['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => $pair['expires_in'],
            'refresh_token' => $pair['refresh_token'],
            'scope' => $row['scope'],
        ], 200);
    }

    private static function grant_refresh_token(array $params)
    {
        $refresh = $params['refresh_token'] ?? '';
        $row = Tokens::get_token((string) $refresh, 'refresh');

        if (!$row) {
            return new \WP_Error('invalid_grant', 'Refresh token is invalid or expired', ['status' => 400]);
        }

        Tokens::revoke_pair($row);
        $pair = Tokens::issue_token_pair($row['client_id'], (int) $row['user_id'], $row['scope']);

        return new \WP_REST_Response([
            'access_token' => $pair['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => $pair['expires_in'],
            'refresh_token' => $pair['refresh_token'],
            'scope' => $row['scope'],
        ], 200);
    }

    public static function revoke(\WP_REST_Request $request)
    {
        $params = $request->get_body_params();
        if (empty($params)) {
            parse_str($request->get_body(), $params);
        }

        $token = $params['token'] ?? '';
        if ($token) {
            Tokens::revoke_by_raw((string) $token);
        }

        return new \WP_REST_Response(null, 200);
    }

    private static function pkce_matches(string $verifier, string $challenge, string $method): bool
    {
        if (strtoupper($method) === 'PLAIN') {
            return hash_equals($challenge, $verifier);
        }

        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return hash_equals($challenge, $computed);
    }

    /**
     * Validates the bearer token on an incoming MCP request.
     * Returns the token row on success, or a WP_Error.
     */
    public static function authenticate_request(\WP_REST_Request $request)
    {
        $header = $request->get_header('authorization') ?: '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return new \WP_Error('unauthorized', 'Missing bearer token', ['status' => 401]);
        }

        $raw = trim($m[1]);
        $row = Tokens::get_token($raw, 'access') ?? Tokens::get_token($raw, 'manual');
        if (!$row) {
            return new \WP_Error('unauthorized', 'Invalid or expired token', ['status' => 401]);
        }

        return $row;
    }
}
