<?php

declare(strict_types=1);

namespace MCPBridge;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Storage/lookup helpers for OAuth clients, auth codes and tokens.
 * Only hashes are persisted; raw secrets are shown to the caller once.
 */
class Tokens
{
    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    public static function random(string $prefix, int $bytes = 32): string
    {
        return $prefix . '_' . rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function register_client(string $name, array $redirect_uris, string $auth_method, ?string $secret): string
    {
        global $wpdb;
        $client_id = self::random('mcpb_client', 16);

        $wpdb->insert($wpdb->prefix . 'mcpb_clients', [
            'client_id' => $client_id,
            'client_secret_hash' => $secret ? self::hash($secret) : null,
            'client_name' => $name,
            'redirect_uris' => wp_json_encode(array_values($redirect_uris)),
            'token_endpoint_auth_method' => $auth_method,
            'created_at' => current_time('mysql', true),
        ]);

        return $client_id;
    }

    public static function get_client(string $client_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}mcpb_clients WHERE client_id = %s",
            $client_id
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        $row['redirect_uris'] = json_decode($row['redirect_uris'], true) ?: [];
        return $row;
    }

    public static function create_auth_code(string $client_id, int $user_id, string $redirect_uri, ?string $challenge, ?string $method, string $scope): string
    {
        global $wpdb;
        $code = self::random('mcpb_ac', 32);

        $wpdb->insert($wpdb->prefix . 'mcpb_auth_codes', [
            'code_hash' => self::hash($code),
            'client_id' => $client_id,
            'user_id' => $user_id,
            'redirect_uri' => $redirect_uri,
            'code_challenge' => $challenge,
            'code_challenge_method' => $method,
            'scope' => $scope,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 600),
            'used' => 0,
            'created_at' => current_time('mysql', true),
        ]);

        return $code;
    }

    public static function consume_auth_code(string $code): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'mcpb_auth_codes';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE code_hash = %s",
            self::hash($code)
        ), ARRAY_A);

        if (!$row || (int) $row['used'] === 1) {
            return null;
        }

        if (strtotime($row['expires_at'] . ' UTC') < time()) {
            return null;
        }

        $wpdb->update($table, ['used' => 1], ['id' => $row['id']]);

        return $row;
    }

    public static function issue_token_pair(string $client_id, int $user_id, string $scope, int $ttl = 3600): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'mcpb_tokens';

        $access = self::random('mcpb_at', 32);
        $refresh = self::random('mcpb_rt', 32);
        $access_hash = self::hash($access);
        $refresh_hash = self::hash($refresh);

        $wpdb->insert($table, [
            'token_hash' => $access_hash,
            'token_type' => 'access',
            'client_id' => $client_id,
            'user_id' => $user_id,
            'scope' => $scope,
            'paired_hash' => $refresh_hash,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl),
            'created_at' => current_time('mysql', true),
        ]);

        $wpdb->insert($table, [
            'token_hash' => $refresh_hash,
            'token_type' => 'refresh',
            'client_id' => $client_id,
            'user_id' => $user_id,
            'scope' => $scope,
            'paired_hash' => $access_hash,
            'expires_at' => null,
            'created_at' => current_time('mysql', true),
        ]);

        return ['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => $ttl];
    }

    /**
     * Issue a single, unpaired, single-purpose token (not part of an
     * OAuth client's token pair) — used for the admin-login exchange
     * token/nonce and the upload-link token. $scope carries whatever JSON
     * metadata the caller needs back when the token is looked up.
     */
    public static function issue_single(string $type, int $user_id, string $scope, int $ttl): string
    {
        global $wpdb;
        $raw = self::random('mcpb_' . $type, 32);

        $wpdb->insert($wpdb->prefix . 'mcpb_tokens', [
            'token_hash' => self::hash($raw),
            'token_type' => $type,
            'client_id' => '',
            'user_id' => $user_id,
            'scope' => $scope,
            'paired_hash' => null,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl),
            'created_at' => current_time('mysql', true),
        ]);

        return $raw;
    }

    public static function get_token(string $raw, string $type): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'mcpb_tokens';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE token_hash = %s AND token_type = %s",
            self::hash($raw),
            $type
        ), ARRAY_A);

        if (!$row || (int) $row['revoked'] === 1) {
            return null;
        }

        if ($row['expires_at'] && strtotime($row['expires_at'] . ' UTC') < time()) {
            return null;
        }

        return $row;
    }

    public static function revoke_pair(array $token_row): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'mcpb_tokens';
        $wpdb->update($table, ['revoked' => 1], ['token_hash' => $token_row['token_hash']]);
        if (!empty($token_row['paired_hash'])) {
            $wpdb->update($table, ['revoked' => 1], ['token_hash' => $token_row['paired_hash']]);
        }
    }

    public static function revoke_by_raw(string $raw): void
    {
        foreach (['access', 'refresh'] as $type) {
            $row = self::get_token($raw, $type);
            if ($row) {
                self::revoke_pair($row);
                return;
            }
        }
    }

    /**
     * A long-lived, unpaired personal access token for clients that can't
     * do an interactive OAuth browser flow (CLI tools reading a static
     * config file). Never expires on its own; only revocation ends it.
     * $scope stores {"label": "..."} so the admin list can show a name.
     */
    public static function issue_manual(int $user_id, string $label): string
    {
        global $wpdb;
        $raw = self::random('mcpb_pat', 32);

        $wpdb->insert($wpdb->prefix . 'mcpb_tokens', [
            'token_hash' => self::hash($raw),
            'token_type' => 'manual',
            'client_id' => '',
            'user_id' => $user_id,
            'scope' => wp_json_encode(['label' => $label]),
            'paired_hash' => null,
            'expires_at' => null,
            'created_at' => current_time('mysql', true),
        ]);

        return $raw;
    }

    public static function list_manual(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, user_id, scope, created_at FROM {$wpdb->prefix}mcpb_tokens
             WHERE token_type = 'manual' AND revoked = 0 ORDER BY created_at DESC",
            ARRAY_A
        );

        return array_map(static function (array $row): array {
            $meta = json_decode((string) $row['scope'], true) ?: [];
            $row['label'] = (string) ($meta['label'] ?? 'Untitled token');
            return $row;
        }, $rows ?: []);
    }

    public static function revoke_manual(int $id): void
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'mcpb_tokens',
            ['revoked' => 1],
            ['id' => $id, 'token_type' => 'manual']
        );
    }
}
