<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Application\Service;

use Semitexa\Mcp\Domain\Contract\McpPromptProviderInterface;
use Semitexa\Mcp\Domain\Contract\McpResourceProviderInterface;
use Semitexa\Mcp\Domain\Contract\McpToolProviderInterface;
use Semitexa\Mcp\Domain\Model\McpTool;
use Semitexa\Mcp\Domain\Model\McpToolResult;

/**
 * The Model Context Protocol over JSON-RPC 2.0: one message in, one out (or
 * none, for a notification). Transport-free, so stdio now and HTTP later speak
 * the same server. What it serves comes from providers (#[AsMcpProvider]):
 * tools, resources and prompts.
 *
 * A tool's failure is a result the agent reads (isError), not a protocol
 * error: an agent that gets a protocol error does not see why. Protocol
 * errors are for what is wrong with the message itself.
 */
final class McpServer
{
    /** Newest first: the one answered when a client asks for one this server does not speak. */
    public const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const RESOURCE_NOT_FOUND = -32002;

    /** @var array<string, array{tool: McpTool, provider: McpToolProviderInterface}>|null name => owner, built on first use */
    private ?array $tools = null;

    /**
     * @param list<object>              $providers in the order a duplicate name is settled: the first keeps it
     * @param \Closure(string): void|null $report    where a duplicate or a failing provider is said (stderr for stdio)
     */
    public function __construct(
        private readonly array $providers,
        private readonly string $name = 'semitexa',
        private readonly string $version = '1',
        private readonly string $instructions = '',
        private readonly ?\Closure $report = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $message
     * @return array<string, mixed>|null the response; null for a notification
     */
    public function handle(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $method = is_string($message['method'] ?? null) ? $message['method'] : '';
        if (($message['jsonrpc'] ?? null) !== '2.0' || $method === '') {
            return self::error($id, self::INVALID_REQUEST, 'Invalid request: a JSON-RPC 2.0 message with a method.');
        }
        if (!array_key_exists('id', $message)) {
            return null; // a notification (notifications/initialized, notifications/cancelled): no answer
        }
        if (!is_string($id) && !is_int($id)) {
            return self::error(null, self::INVALID_REQUEST, 'Invalid request: the id is a string or an integer.');
        }
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        return match ($method) {
            'initialize' => self::result($id, $this->initialize($params)),
            'ping' => self::result($id, []),
            'tools/list' => self::result($id, ['tools' => array_values(array_map(
                static fn (array $entry): array => $entry['tool']->toArray(),
                $this->tools(),
            ))]),
            'tools/call' => $this->call($id, $params),
            'resources/list' => self::result($id, ['resources' => $this->listResources()]),
            'resources/read' => $this->read($id, $params),
            'prompts/list' => self::result($id, ['prompts' => $this->listPrompts()]),
            'prompts/get' => $this->prompt($id, $params),
            default => self::error($id, self::METHOD_NOT_FOUND, sprintf('No method "%s".', $method)),
        };
    }

    /**
     * @param array<array-key, mixed> $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $asked = $params['protocolVersion'] ?? null;
        $capabilities = ['tools' => ['listChanged' => false]];
        if ($this->of(McpResourceProviderInterface::class) !== []) {
            $capabilities['resources'] = ['listChanged' => false];
        }
        if ($this->of(McpPromptProviderInterface::class) !== []) {
            $capabilities['prompts'] = ['listChanged' => false];
        }

        return array_filter([
            'protocolVersion' => in_array($asked, self::PROTOCOL_VERSIONS, true) ? $asked : self::PROTOCOL_VERSIONS[0],
            'capabilities' => $capabilities,
            'serverInfo' => ['name' => $this->name, 'version' => $this->version],
            'instructions' => $this->instructions !== '' ? $this->instructions : null,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /** @return array<string, array{tool: McpTool, provider: McpToolProviderInterface}> */
    private function tools(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }
        $tools = [];
        foreach ($this->of(McpToolProviderInterface::class) as $provider) {
            try {
                $offered = $provider->tools();
            } catch (\Throwable $e) {
                $this->say(sprintf('%s offers no tools: %s', $provider::class, $e->getMessage()));
                continue;
            }
            foreach ($offered as $tool) {
                if (isset($tools[$tool->name])) {
                    $this->say(sprintf('Tool "%s" of %s is dropped: %s has it.', $tool->name, $provider::class, $tools[$tool->name]['provider']::class));
                    continue;
                }
                $tools[$tool->name] = ['tool' => $tool, 'provider' => $provider];
            }
        }

        return $this->tools = $tools;
    }

    /** @param array<array-key, mixed> $params */
    private function call(int|string $id, array $params): array
    {
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        $entry = $this->tools()[$name] ?? null;
        if ($entry === null) {
            return self::error($id, self::INVALID_PARAMS, sprintf('No tool "%s".', $name));
        }
        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            return self::error($id, self::INVALID_PARAMS, sprintf('The arguments of "%s" are an object.', $name));
        }
        /** @var array<string, mixed> $arguments */
        $missing = array_values(array_filter($entry['tool']->requiredInputs(), static fn (string $k): bool => !array_key_exists($k, $arguments)));
        if ($missing !== []) {
            return self::error($id, self::INVALID_PARAMS, sprintf('"%s" needs %s.', $name, implode(', ', $missing)));
        }

        try {
            $result = $entry['provider']->call($name, $arguments);
        } catch (\Throwable $e) {
            // The agent reads it and can try otherwise; a protocol error would hide why.
            $result = McpToolResult::error(sprintf('%s failed: %s', $name, $e->getMessage()));
        }

        return self::result($id, $result->toArray());
    }

    /** @return list<array<string, string>> */
    private function listResources(): array
    {
        $listed = [];
        foreach ($this->of(McpResourceProviderInterface::class) as $provider) {
            try {
                foreach ($provider->resources() as $resource) {
                    $listed[] = $resource->toArray();
                }
            } catch (\Throwable $e) {
                $this->say(sprintf('%s lists no resources: %s', $provider::class, $e->getMessage()));
            }
        }

        return $listed;
    }

    /** @param array<array-key, mixed> $params */
    private function read(int|string $id, array $params): array
    {
        $uri = is_string($params['uri'] ?? null) ? $params['uri'] : '';
        foreach ($this->of(McpResourceProviderInterface::class) as $provider) {
            try {
                $content = $provider->read($uri);
            } catch (\Throwable $e) {
                return self::error($id, self::RESOURCE_NOT_FOUND, sprintf('"%s" could not be read: %s', $uri, $e->getMessage()));
            }
            if ($content !== null) {
                return self::result($id, ['contents' => [['uri' => $uri] + $content]]);
            }
        }

        return self::error($id, self::RESOURCE_NOT_FOUND, sprintf('No resource "%s".', $uri));
    }

    /** @return list<array<string, mixed>> */
    private function listPrompts(): array
    {
        $listed = [];
        foreach ($this->of(McpPromptProviderInterface::class) as $provider) {
            try {
                foreach ($provider->prompts() as $prompt) {
                    $listed[] = $prompt->toArray();
                }
            } catch (\Throwable $e) {
                $this->say(sprintf('%s lists no prompts: %s', $provider::class, $e->getMessage()));
            }
        }

        return $listed;
    }

    /** @param array<array-key, mixed> $params */
    private function prompt(int|string $id, array $params): array
    {
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        $arguments = is_array($params['arguments'] ?? null)
            ? array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $params['arguments'])
            : [];
        foreach ($this->of(McpPromptProviderInterface::class) as $provider) {
            $messages = $provider->get($name, $arguments);
            if ($messages !== null) {
                return self::result($id, ['messages' => array_map(
                    static fn (array $m): array => ['role' => $m['role'], 'content' => ['type' => 'text', 'text' => $m['text']]],
                    $messages,
                )]);
            }
        }

        return self::error($id, self::INVALID_PARAMS, sprintf('No prompt "%s".', $name));
    }

    /**
     * @template T of object
     * @param class-string<T> $contract
     * @return list<T>
     */
    private function of(string $contract): array
    {
        return array_values(array_filter($this->providers, static fn (object $p): bool => $p instanceof $contract));
    }

    private function say(string $line): void
    {
        if ($this->report !== null) {
            ($this->report)($line);
        }
    }

    /** @return array<string, mixed> */
    public static function result(int|string|null $id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result === [] ? new \stdClass() : $result];
    }

    /** @return array<string, mixed> */
    public static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => is_int($id) || is_string($id) ? $id : null, 'error' => ['code' => $code, 'message' => $message]];
    }
}
