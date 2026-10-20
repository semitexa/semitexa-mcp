<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Mcp\Application\Service\McpServer;
use Semitexa\Mcp\Domain\Contract\McpPromptProviderInterface;
use Semitexa\Mcp\Domain\Contract\McpResourceProviderInterface;
use Semitexa\Mcp\Domain\Contract\McpToolProviderInterface;
use Semitexa\Mcp\Domain\Model\McpResourceEntry;
use Semitexa\Mcp\Domain\Model\McpPrompt;
use Semitexa\Mcp\Domain\Model\McpTool;
use Semitexa\Mcp\Domain\Model\McpToolResult;

/**
 * The protocol as a client sees it (tk-mcp-core-server): what initialize
 * answers, how tools from several providers are listed and called, and that a
 * tool's failure reaches the agent as a result it can read.
 */
final class McpServerTest extends TestCase
{
    /** @var list<string> */
    private array $said = [];

    #[Test]
    public function initialize_answers_the_version_asked_when_spoken_and_the_newest_otherwise(): void
    {
        $asked = $this->server([new ProbeTools()])->handle($this->request('initialize', ['protocolVersion' => '2025-03-26']));
        $unknown = $this->server([new ProbeTools()])->handle($this->request('initialize', ['protocolVersion' => '1999-01-01']));

        self::assertSame('2025-03-26', $asked['result']['protocolVersion']);
        self::assertSame('2025-06-18', $unknown['result']['protocolVersion']);
        self::assertSame(['name' => 'semitexa', 'version' => '1'], $asked['result']['serverInfo']);
        self::assertSame('Check before you report.', $asked['result']['instructions']);
    }

    /** A client offers resources and prompts only when the server says it has them. */
    #[Test]
    public function initialize_names_only_the_capabilities_some_provider_gives(): void
    {
        $toolsOnly = $this->server([new ProbeTools()])->handle($this->request('initialize'));
        $all = $this->server([new ProbeTools(), new ProbeDocs()])->handle($this->request('initialize'));

        self::assertSame(['tools'], array_keys($toolsOnly['result']['capabilities']));
        self::assertSame(['tools', 'resources', 'prompts'], array_keys($all['result']['capabilities']));
    }

    #[Test]
    public function tools_of_every_provider_are_listed_with_their_hints_and_a_duplicate_name_is_said(): void
    {
        $response = $this->server([new ProbeTools(), new ProbeDuplicateTools()])->handle($this->request('tools/list'));

        self::assertSame(['echo', 'fail', 'throw', 'other'], array_column($response['result']['tools'], 'name'));
        self::assertSame(
            ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            $response['result']['tools'][0]['annotations'],
        );
        self::assertEquals(new \stdClass(), $response['result']['tools'][3]['inputSchema']['properties'], 'an empty object, not a list');
        self::assertSame(['Tool "echo" of ' . ProbeDuplicateTools::class . ' is dropped: ' . ProbeTools::class . ' has it.'], $this->said);
    }

    #[Test]
    public function a_tool_is_called_with_its_arguments_and_answers_text_and_structured_content(): void
    {
        $response = $this->server([new ProbeTools()])->handle($this->request('tools/call', ['name' => 'echo', 'arguments' => ['word' => 'hi']]));

        self::assertSame(['said' => 'hi'], $response['result']['structuredContent']);
        self::assertFalse($response['result']['isError']);
        self::assertSame("{\n    \"said\": \"hi\"\n}", $response['result']['content'][0]['text']);
    }

    /** The agent must see why, so it can try otherwise: a result, not a protocol error. */
    #[Test]
    public function a_failing_or_throwing_tool_answers_a_result_the_agent_reads(): void
    {
        $server = $this->server([new ProbeTools()]);

        $failed = $server->handle($this->request('tools/call', ['name' => 'fail', 'arguments' => []]));
        $threw = $server->handle($this->request('tools/call', ['name' => 'throw']));

        self::assertSame(['content' => [['type' => 'text', 'text' => 'verify did not pass']], 'isError' => true], $failed['result']);
        self::assertSame(['content' => [['type' => 'text', 'text' => 'throw failed: disk on fire']], 'isError' => true], $threw['result']);
    }

    #[Test]
    public function an_unknown_tool_missing_arguments_or_a_list_of_arguments_is_an_invalid_params_error(): void
    {
        $server = $this->server([new ProbeTools()]);

        self::assertSame(['code' => -32602, 'message' => 'No tool "nope".'], $server->handle($this->request('tools/call', ['name' => 'nope']))['error']);
        self::assertSame(['code' => -32602, 'message' => '"echo" needs word.'], $server->handle($this->request('tools/call', ['name' => 'echo', 'arguments' => []]))['error']);
        self::assertSame(['code' => -32602, 'message' => 'The arguments of "echo" are an object.'], $server->handle($this->request('tools/call', ['name' => 'echo', 'arguments' => ['hi']]))['error']);
    }

    #[Test]
    public function resources_are_listed_and_read_and_an_unknown_one_is_not_found(): void
    {
        $server = $this->server([new ProbeDocs()]);

        self::assertSame([['uri' => 'semitexa://docs/intro', 'name' => 'intro', 'mimeType' => 'text/markdown']], $server->handle($this->request('resources/list'))['result']['resources']);
        self::assertSame(
            [['uri' => 'semitexa://docs/intro', 'mimeType' => 'text/markdown', 'text' => '# Intro']],
            $server->handle($this->request('resources/read', ['uri' => 'semitexa://docs/intro']))['result']['contents'],
        );
        self::assertSame(['code' => -32002, 'message' => 'No resource "semitexa://docs/none".'], $server->handle($this->request('resources/read', ['uri' => 'semitexa://docs/none']))['error']);
    }

    #[Test]
    public function prompts_are_listed_and_got_with_their_arguments(): void
    {
        $server = $this->server([new ProbeDocs()]);

        self::assertSame('add_html_page', $server->handle($this->request('prompts/list'))['result']['prompts'][0]['name']);
        self::assertSame(
            [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Add an HTML page at /pricing.']]],
            $server->handle($this->request('prompts/get', ['name' => 'add_html_page', 'arguments' => ['path' => '/pricing']]))['result']['messages'],
        );
        self::assertSame(-32602, $server->handle($this->request('prompts/get', ['name' => 'nope']))['error']['code']);
    }

    #[Test]
    public function a_notification_gets_no_answer_and_a_bad_message_or_method_an_error(): void
    {
        $server = $this->server([new ProbeTools()]);

        self::assertNull($server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
        self::assertSame(-32600, $server->handle(['method' => 'ping', 'id' => 1])['error']['code']);
        self::assertSame(-32600, $server->handle(['jsonrpc' => '2.0', 'method' => 'ping', 'id' => ['x']])['error']['code']);
        self::assertSame(['code' => -32601, 'message' => 'No method "tools/delete".'], $server->handle($this->request('tools/delete'))['error']);
        self::assertEquals(['jsonrpc' => '2.0', 'id' => 7, 'result' => new \stdClass()], $server->handle($this->request('ping')));
    }

    /** @param list<object> $providers */
    private function server(array $providers): McpServer
    {
        $said = &$this->said;

        return new McpServer($providers, 'semitexa', '1', 'Check before you report.', static function (string $line) use (&$said): void {
            $said[] = $line;
        });
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function request(string $method, array $params = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => 7, 'method' => $method, 'params' => $params];
    }
}

final class ProbeTools implements McpToolProviderInterface
{
    public function tools(): array
    {
        return [
            new McpTool('echo', 'Says the word back.', ['type' => 'object', 'properties' => ['word' => ['type' => 'string']], 'required' => ['word']], readOnly: true, idempotent: true),
            new McpTool('fail', 'Always fails.'),
            new McpTool('throw', 'Always throws.'),
        ];
    }

    public function call(string $name, array $arguments): McpToolResult
    {
        return match ($name) {
            'echo' => McpToolResult::json(['said' => $arguments['word']]),
            'fail' => McpToolResult::error('verify did not pass'),
            default => throw new \RuntimeException('disk on fire'),
        };
    }
}

final class ProbeDuplicateTools implements McpToolProviderInterface
{
    public function tools(): array
    {
        return [new McpTool('echo', 'A second echo.'), new McpTool('other', 'Another tool.')];
    }

    public function call(string $name, array $arguments): McpToolResult
    {
        return McpToolResult::text($name);
    }
}

final class ProbeDocs implements McpResourceProviderInterface, McpPromptProviderInterface
{
    public function resources(): array
    {
        return [new McpResourceEntry('semitexa://docs/intro', 'intro')];
    }

    public function read(string $uri): ?array
    {
        return $uri === 'semitexa://docs/intro' ? ['mimeType' => 'text/markdown', 'text' => '# Intro'] : null;
    }

    public function prompts(): array
    {
        return [new McpPrompt('add_html_page', 'Add an HTML page.', [['name' => 'path', 'description' => 'Its path', 'required' => true]])];
    }

    public function get(string $name, array $arguments): ?array
    {
        return $name === 'add_html_page' ? [['role' => 'user', 'text' => 'Add an HTML page at ' . ($arguments['path'] ?? '/') . '.']] : null;
    }
}
