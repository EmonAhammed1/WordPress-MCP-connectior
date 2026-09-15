<?php

declare(strict_types=1);

namespace MCPBridge;

if (!defined('ABSPATH')) {
    exit;
}

class Activator
{
    public static function activate(): void
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'mcpb_';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_clients = "CREATE TABLE {$prefix}clients (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id VARCHAR(64) NOT NULL,
            client_secret_hash VARCHAR(255) DEFAULT NULL,
            client_name VARCHAR(255) DEFAULT '',
            redirect_uris LONGTEXT NOT NULL,
            token_endpoint_auth_method VARCHAR(32) NOT NULL DEFAULT 'none',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY client_id (client_id)
        ) $charset_collate;";

        $sql_auth_codes = "CREATE TABLE {$prefix}auth_codes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code_hash VARCHAR(64) NOT NULL,
            client_id VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            redirect_uri TEXT NOT NULL,
            code_challenge VARCHAR(255) DEFAULT NULL,
            code_challenge_method VARCHAR(16) DEFAULT NULL,
            scope VARCHAR(255) DEFAULT '',
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code_hash (code_hash)
        ) $charset_collate;";

        $sql_tokens = "CREATE TABLE {$prefix}tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            token_hash VARCHAR(64) NOT NULL,
            token_type VARCHAR(32) NOT NULL,
            client_id VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            scope TEXT DEFAULT NULL,
            paired_hash VARCHAR(64) DEFAULT NULL,
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            expires_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash)
        ) $charset_collate;";

        dbDelta($sql_clients);
        dbDelta($sql_auth_codes);
        dbDelta($sql_tokens);

        add_option('mcpb_db_version', MCPB_DB_VERSION);
        add_option('mcpb_tool_categories', Tools::default_categories());
        add_option('mcpb_fs_root', ABSPATH);
        add_option('mcpb_skills_dir', WP_CONTENT_DIR . '/mcp-bridge-skills');

        if (!get_option('mcpb_issuer_secret')) {
            add_option('mcpb_issuer_secret', wp_generate_password(64, false, false));
        }
    }
}
