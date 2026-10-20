<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Application\Service;

/**
 * MCP's stdio transport: one JSON-RPC message per line in, one per line out,
 * until the input closes. The output carries protocol messages and nothing
 * else: whatever the tools print must not reach it, or the client reads a
 * broken stream. Diagnostics go to the error stream.
 */
final class StdioTransport
{
    /**
     * @param resource      $in
     * @param resource      $out
     * @param resource|null $err where what a tool prints goes; STDERR when null
     */
    public function run(McpServer $server, $in, $out, $err = null): void
    {
        $err ??= STDERR;
        while (($line = fgets($in)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $message = json_decode($line, true);
            $response = is_array($message) && !array_is_list($message)
                ? $this->answer($server, $message, $err)
                : McpServer::error(null, McpServer::PARSE_ERROR, 'Parse error: one JSON-RPC object per line.');
            if ($response === null) {
                continue;
            }
            fwrite($out, json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
            fflush($out);
        }
    }

    /**
     * A tool that prints instead of returning would break the stream: what it
     * prints is kept off the output.
     *
     * @param array<array-key, mixed> $message
     * @param resource                $err
     * @return array<string, mixed>|null
     */
    private function answer(McpServer $server, array $message, $err): ?array
    {
        ob_start();
        try {
            return $server->handle($message);
        } finally {
            $stray = (string) ob_get_clean();
            if ($stray !== '') {
                fwrite($err, $stray);
            }
        }
    }
}
