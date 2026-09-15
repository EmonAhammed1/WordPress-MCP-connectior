<?php

declare(strict_types=1);

namespace MCPBridge;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MCP (Model Context Protocol) JSON-RPC handling over Streamable HTTP.
 * One POST endpoint; every response is a single JSON object (no SSE),
 * which the MCP spec allows for servers that don't need server push.
 */
class MCP_Server
{
    public const PROTOCOL_VERSION = '2025-06-18';

    public static function handle(\WP_REST_Request $request)
    {
        $body = json_decode($request->get_body(), true);

        if (!is_array($body) || !isset($body['jsonrpc'])) {
            return new \WP_REST_Response(self::error(null, -32600, 'Invalid JSON-RPC request'), 400);
        }

        $id = $body['id'] ?? null;
        $method = $body['method'] ?? '';
        $params = $body['params'] ?? [];

        // Notifications carry no id and expect no response body.
        $is_notification = !array_key_exists('id', $body);

        try {
            $result = match ($method) {
                'initialize' => self::initialize((array) $params),
                'notifications/initialized' => null,
                'ping' => new \stdClass(),
                'tools/list' => ['tools' => Tools::definitions()],
                'tools/call' => Tools::call((string) ($params['name'] ?? ''), (array) ($params['arguments'] ?? [])),
                'prompts/list' => ['prompts' => Skills::mcp_prompt_list()],
                'prompts/get' => self::get_prompt((array) $params),
                default => 'method_not_found',
            };
        } catch (\Throwable $e) {
            if ($is_notification) {
                return new \WP_REST_Response(null, 202);
            }
            return new \WP_REST_Response(self::error($id, -32602, $e->getMessage()), 200);
        }

        if ($is_notification) {
            return new \WP_REST_Response(null, 202);
        }

        if ($result === 'method_not_found') {
            return new \WP_REST_Response(self::error($id, -32601, "Method not found: {$method}"), 200);
        }

        $session_id = $request->get_header('mcp-session-id') ?: wp_generate_uuid4();
        $response = new \WP_REST_Response(self::success($id, $result), 200);
        $response->header('Mcp-Session-Id', $session_id);

        return $response;
    }

    private static function initialize(array $params): array
    {
        // Mirror back whatever protocol version the client asked for rather
        // than hardcoding one: our JSON-RPC surface doesn't depend on
        // version-specific behavior, and some MCP clients hard-reject a
        // server that responds with a version string they don't recognize.
        $requested_version = $params['protocolVersion'] ?? null;
        $version = is_string($requested_version) && $requested_version !== ''
            ? $requested_version
            : self::PROTOCOL_VERSION;

        return [
            'protocolVersion' => $version,
            'capabilities' => ['tools' => new \stdClass(), 'prompts' => new \stdClass()],
            'serverInfo' => [
                'name' => 'mcp-bridge',
                'title' => 'MCP Bridge for Claude',
                'version' => MCPB_VERSION,
            ],
            'instructions' => self::instructions(),
        ];
    }

    /**
     * Free-text operating guidance surfaced through the standard MCP
     * `initialize.instructions` field, so a client that reads it (Claude
     * does) sees this before making its first tool call.
     */
    private static function instructions(): string
    {
        $enabled = array_keys(array_filter(Tools::enabled_categories()));

        return implode("\n", [
            'MCP Bridge for Claude gives you real administrative access to this WordPress site.',
            'Enabled tool categories right now: ' . (implode(', ', $enabled) ?: '(none)') . '.',
            'A category must be turned on from Settings > MCP Bridge on the site before its tools appear here — if a tool you expect is missing, tell the site owner which category to enable.',
            '',
            'Tips:',
            '- Prefer edit_file for a small, precise change to an existing file over rewriting it with write_file.',
            '- create_post/update_post accept raw HTML or Gutenberg block markup as content. If the result includes a "warnings" field, one or more blocks aren\'t registered server-side (usually third-party blocks) — their markup was written as-is and was not validated by a real editor.',
            '- execute_php, run_wp_cli and run_sql are unrestricted code/data access, equivalent in power to a server shell. Prefer a narrower, purpose-built tool when one exists.',
            '- Files saved under the skills directory (see get_site_info) are exposed to you as MCP prompts via prompts/list and prompts/get.',
        ]);
    }

    private static function get_prompt(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $content = Skills::get($name);
        if ($content === null) {
            throw new \RuntimeException("Unknown prompt: {$name}");
        }

        return [
            'description' => "Skill: {$name}",
            'messages' => [
                ['role' => 'user', 'content' => ['type' => 'text', 'text' => $content]],
            ],
        ];
    }

    private static function success($id, $result): array
    {
        // An empty PHP array serializes to JSON "[]", but a JSON-RPC/MCP
        // result must be an object ("{}") when there's nothing in it.
        if ($result === null || (is_array($result) && empty($result))) {
            $result = new \stdClass();
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private static function error($id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
