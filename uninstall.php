<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

foreach (['clients', 'auth_codes', 'tokens'] as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}mcpb_{$table}");
}

delete_option('mcpb_db_version');
delete_option('mcpb_tool_categories');
delete_option('mcpb_fs_root');
delete_option('mcpb_skills_dir');
delete_option('mcpb_issuer_secret');
