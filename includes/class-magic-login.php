<?php

declare(strict_types=1);

namespace MCPBridge;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Two-step, header-authenticated magic login link, matching the property
 * the "create admin login link" tool advertises: the real bearer secret
 * (from create_admin_login_link) travels only in a header, on a request
 * Claude's own backend makes. Exchanging it mints a second, much
 * shorter-lived, single-use nonce, and only THAT ever appears in a
 * navigable URL a human's browser follows.
 */
class Magic_Login
{
    public static function init(): void
    {
        // The nonce-consuming redirect sets an auth cookie and sends a
        // browser on to wp-admin, so — like OAuth_Server's /authorize —
        // it can't go through register_rest_route(): WordPress's REST
        // cookie-auth layer would demand an X-WP-Nonce header a plain
        // browser navigation can never send. Handle it directly on 'init'.
        add_action('init', [self::class, 'maybe_handle_redirect'], 5);
    }

    public static function exchange_url(): string
    {
        return rest_url(MCPB_NAMESPACE . '/admin-login/exchange');
    }

    public static function redirect_base_url(): string
    {
        return rtrim(rest_url(MCPB_NAMESPACE . '/admin-login'), '/') . '/';
    }

    public static function maybe_handle_redirect(): void
    {
        $nonce = self::extract_nonce_from_request();
        if ($nonce === null) {
            return;
        }

        self::handle_redirect($nonce);
    }

    private static function extract_nonce_from_request(): ?string
    {
        $base_path = parse_url(self::redirect_base_url(), PHP_URL_PATH);
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

        if (is_string($base_path) && is_string($path) && str_starts_with($path, $base_path)) {
            $candidate = substr($path, strlen($base_path));
            return self::is_nonce_segment($candidate) ? rawurldecode($candidate) : null;
        }

        // Fallback for sites without pretty permalinks (index.php?rest_route=/...).
        $rest_route = $_GET['rest_route'] ?? '';
        $prefix = '/' . MCPB_NAMESPACE . '/admin-login/';
        if (is_string($rest_route) && str_starts_with($rest_route, $prefix)) {
            $candidate = substr($rest_route, strlen($prefix));
            return self::is_nonce_segment($candidate) ? rawurldecode($candidate) : null;
        }

        return null;
    }

    private static function is_nonce_segment(string $candidate): bool
    {
        // "exchange" is the literal POST route registered in Rest_Routes;
        // never treat it as a nonce value.
        return $candidate !== '' && $candidate !== 'exchange';
    }

    private static function handle_redirect(string $nonce): void
    {
        $row = Tokens::get_token($nonce, 'admin_login_nonce');
        if (!$row) {
            status_header(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'This login link is invalid, already used, or has expired.';
            exit;
        }

        Tokens::revoke_by_raw($nonce);

        $meta = json_decode((string) $row['scope'], true) ?: [];
        $redirect_path = ltrim((string) ($meta['redirect'] ?? ''), '/');

        wp_set_auth_cookie((int) $row['user_id'], true);
        wp_set_current_user((int) $row['user_id']);

        wp_redirect(admin_url($redirect_path));
        exit;
    }

    /**
     * REST callback for POST /admin-login/exchange. Server-to-server: no
     * WP cookies involved, so this is a plain registered REST route.
     */
    public static function exchange(\WP_REST_Request $request)
    {
        $token = $request->get_header('x-mcpb-admin-access-token') ?: '';
        if (!$token) {
            return new \WP_REST_Response(['error' => 'Missing X-MCPB-Admin-Access-Token header'], 401);
        }

        $row = Tokens::get_token($token, 'admin_login');
        if (!$row) {
            return new \WP_REST_Response(['error' => 'Invalid or expired token'], 401);
        }

        Tokens::revoke_by_raw($token);

        $meta = json_decode((string) $row['scope'], true) ?: [];
        $nonce_ttl = 60;
        $nonce_meta = wp_json_encode(['redirect' => (string) ($meta['redirect'] ?? '')]);
        $nonce = Tokens::issue_single('admin_login_nonce', (int) $row['user_id'], (string) $nonce_meta, $nonce_ttl);

        return new \WP_REST_Response([
            'login_url' => self::redirect_base_url() . rawurlencode($nonce),
            'expires_in' => $nonce_ttl,
            'one_time' => true,
        ], 200);
    }
}
