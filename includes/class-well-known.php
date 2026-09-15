<?php

declare(strict_types=1);

namespace MCPBridge;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Serves the OAuth discovery documents at the site root, bypassing WP
 * rewrite/permalinks entirely, since MCP clients fetch these from
 * /.well-known/... regardless of how the site's permalinks are configured.
 */
class Well_Known
{
    public static function init(): void
    {
        add_action('init', [self::class, 'maybe_serve'], 0);
    }

    public static function maybe_serve(): void
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (!is_string($uri)) {
            return;
        }

        if (str_starts_with($uri, '/.well-known/oauth-authorization-server')) {
            self::send_json(self::authorization_server_metadata());
        }

        if (str_starts_with($uri, '/.well-known/oauth-protected-resource')) {
            self::send_json(self::protected_resource_metadata());
        }
    }

    public static function authorization_server_metadata(): array
    {
        $issuer = home_url('/');

        return [
            'issuer' => untrailingslashit($issuer),
            'authorization_endpoint' => rest_url(MCPB_NAMESPACE . '/authorize'),
            'token_endpoint' => rest_url(MCPB_NAMESPACE . '/token'),
            'registration_endpoint' => rest_url(MCPB_NAMESPACE . '/register'),
            'revocation_endpoint' => rest_url(MCPB_NAMESPACE . '/revoke'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
            'scopes_supported' => ['mcp'],
        ];
    }

    public static function protected_resource_metadata(): array
    {
        return [
            'resource' => rest_url(MCPB_NAMESPACE . MCPB_MCP_ROUTE),
            'authorization_servers' => [untrailingslashit(home_url('/'))],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => ['mcp'],
        ];
    }

    private static function send_json(array $data): void
    {
        status_header(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo wp_json_encode($data);
        exit;
    }
}
