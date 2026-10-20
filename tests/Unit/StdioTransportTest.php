<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Mcp\Application\Service\McpServer;
use Semitexa\Mcp\Application\Service\StdioTransport;
use Semitexa\Mcp\Domain\Contract\McpToolProviderInterface;
use Semitexa\Mcp\Domain\Model\McpTool;
use Semitexa\Mcp\Domain\Model\McpToolResult;

/**
 * MCP over stdio: a line in, a line out, and nothing else on the output, not
 * even what a tool prints, or the client reads a broken stream.
 */
final class StdioTransportTest extends TestCase
{
    private string $stray = '';

    #[Test]
    public function each_request_line_gets_one_response_line_and_a_notification_none(): void
    {
        $out = $this->exchange(implode("\n", [
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}',
            '{"jsonrpc":"2.0","method":"notifications/initialized"}',
            '',
            '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"chatty","arguments":{}}}',
        ]) . "\n");

        self::assertCount(2, $out);
        self::assertSame(1, $out[0]['id']);
        self::assertSame('2025-06-18', $out[0]['result']['protocolVersion']);
        self::assertSame(['content' => [['type' => 'text', 'text' => 'done']], 'isError' => false], $out[1]['result']);
        self::assertSame("debug output that must not reach the client\n", $this->stray, 'what the tool printed went to the error stream');
    }

    #[Test]
    public function a_line_that_is_not_a_json_object_is_a_parse_error_and_the_next_still_answered(): void
    {
        $out = $this->exchange("not json\n[1,2]\n{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"ping\"}\n");

        self::assertSame([-32700, -32700], [$out[0]['error']['code'], $out[1]['error']['code']]);
        self::assertSame(3, $out[2]['id']);
    }

    /** @return list<array<string, mixed>> every response line, decoded */
    private function exchange(string $input): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);
        self::assertIsResource($err);
        fwrite($in, $input);
        rewind($in);

        (new StdioTransport())->run(new McpServer([new ChattyTools()]), $in, $out, $err);
        rewind($err);
        $this->stray = (string) stream_get_contents($err);

        rewind($out);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($out)), static fn (string $l): bool => $l !== ''));

        return array_map(static fn (string $l): array => json_decode($l, true, flags: JSON_THROW_ON_ERROR), $lines);
    }
}

/** A tool that prints, as a careless one would. */
final class ChattyTools implements McpToolProviderInterface
{
    public function tools(): array
    {
        return [new McpTool('chatty', 'Prints, then answers.')];
    }

    public function call(string $name, array $arguments): McpToolResult
    {
        echo "debug output that must not reach the client\n";

        return McpToolResult::text('done');
    }
}
