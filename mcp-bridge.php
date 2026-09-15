<?php

declare(strict_types=1);

/**
 * Plugin Name: MCP Bridge for Claude
 * Plugin URI: https://example.com/mcp-bridge
 * Description: Turns this WordPress site into a remote MCP server with OAuth 2.1, so it can be added as a Claude Connector (and, via a generated access token, most other MCP-capable AI tools too). Gives Claude full PHP execution, WP-CLI, filesystem, database and content/plugin/user control, plus a file-backed MCP prompts (skills) store. For development and staging environments only.
 * Version: 1.3.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Rayhan
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mcp-bridge
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MCPB_VERSION', '1.3.0');
define('MCPB_PLUGIN_FILE', __FILE__);
define('MCPB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MCPB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MCPB_DB_VERSION', '1');

// MCP endpoint route: /wp-json/mcp-bridge/v1/mcp
define('MCPB_NAMESPACE', 'mcp-bridge/v1');
define('MCPB_MCP_ROUTE', '/mcp');

require_once MCPB_PLUGIN_DIR . 'includes/class-activator.php';
require_once MCPB_PLUGIN_DIR . 'includes/class-tokens.php';
require_once MCPB_PLUGIN_DIR . 'includes/class-oauth-server.php';
require_once MCPB_PLUGIN_DIR . 'includes/class-magic-login.php';
require_once MCPB_PLUGIN_DIR . 'includes/class-well-known.php';
require_once MCPB_PLUGIN_DIR . 'includes/class-skills.php';
require_once MCPB_PLUGIN_DIR . 'includes/class-tools.php';
require_once MCPB_PLUGIN_DIR . 'includes/class-mcp-server.php';
require_once MCPB_PLUGIN_DIR . 'includes/class-rest-routes.php';
require_once MCPB_PLUGIN_DIR . 'includes/class-connect-guide.php';
require_once MCPB_PLUGIN_DIR . 'includes/class-admin-page.php';

register_activation_hook(__FILE__, ['MCPBridge\\Activator', 'activate']);

add_action('plugins_loaded', function () {
    \MCPBridge\Well_Known::init();
    \MCPBridge\OAuth_Server::init();
    \MCPBridge\Magic_Login::init();
    \MCPBridge\Rest_Routes::init();
    \MCPBridge\Admin_Page::init();
});
