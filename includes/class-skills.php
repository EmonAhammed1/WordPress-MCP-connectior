<?php

declare(strict_types=1);

namespace MCPBridge;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A lightweight, file-backed "skills" store: markdown files under a
 * configurable directory, exposed to Claude through the real MCP `prompts`
 * capability (prompts/list, prompts/get). Skills are read and written
 * through the existing filesystem tools (read_file/write_file/edit_file) —
 * this class just discovers them and formats them for the MCP protocol.
 */
class Skills
{
    public static function directory(): string
    {
        $dir = (string) get_option('mcpb_skills_dir', WP_CONTENT_DIR . '/mcp-bridge-skills');
        return rtrim($dir, '/');
    }

    /** @return list<array{name: string, title: string, description: string}> */
    public static function list(): array
    {
        $dir = self::directory();
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.md') ?: [];
        sort($files);

        $out = [];
        foreach ($files as $file) {
            $name = basename($file, '.md');
            $meta = self::read_meta($file);
            $out[] = ['name' => $name, 'title' => $meta['title'], 'description' => $meta['description']];
        }

        return $out;
    }

    /** @return list<array{name: string, title?: string, description?: string}> */
    public static function mcp_prompt_list(): array
    {
        return array_map(
            fn ($skill) => array_filter([
                'name' => $skill['name'],
                'title' => $skill['title'] !== $skill['name'] ? $skill['title'] : null,
                'description' => $skill['description'] !== '' ? $skill['description'] : null,
            ], fn ($v) => $v !== null),
            self::list()
        );
    }

    public static function get(string $name): ?string
    {
        $file = self::path_for($name);
        if (!is_file($file)) {
            return null;
        }
        return (string) file_get_contents($file);
    }

    public static function path_for(string $name): string
    {
        // Whitelist filename characters so a skill name can never escape
        // the skills directory (no slashes, no "..", nothing shell/path-y).
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $name) ?? '';
        return self::directory() . '/' . $safe . '.md';
    }

    /** @return array{title: string, description: string} */
    private static function read_meta(string $file): array
    {
        $slug = basename($file, '.md');
        $content = (string) file_get_contents($file, false, null, 0, 4096);

        $title = null;
        $description = '';
        $body = $content;

        if (preg_match('/^---\s*\n(.*?)\n---\s*\n?(.*)$/s', $content, $m)) {
            foreach (explode("\n", $m[1]) as $line) {
                if (preg_match('/^(title|description)\s*:\s*(.+)$/i', trim($line), $fm)) {
                    $value = trim($fm[2], " \t\"'");
                    if (strtolower($fm[1]) === 'title') {
                        $title = $value;
                    } else {
                        $description = $value;
                    }
                }
            }
            $body = ltrim($m[2]);
        }

        if ($title === null) {
            $title = trim((string) strtok($body, "\n"), "# \t");
            $title = $title !== '' ? $title : $slug;
        }

        if ($description === '') {
            foreach (explode("\n", $body) as $line) {
                $line = trim($line);
                if ($line !== '' && !str_starts_with($line, '#')) {
                    $description = mb_substr($line, 0, 200);
                    break;
                }
            }
        }

        return ['title' => $title, 'description' => $description];
    }
}
