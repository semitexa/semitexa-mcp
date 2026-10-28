<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Tests\Unit\Relay;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * server:restart recreated the app container and the MCP server inside it:
 * the agent was left "disconnected" with no Semitexa tools. The relay keeps
 * the agent's connection, answers what the dead server never will, starts a
 * new one and replays the handshake so the next call simply works.
 */
final class McpRelayTest extends TestCase
{
    private const RELAY = __DIR__ . '/../../../bin/mcp-relay.php';

    /** @var resource */
    private $proc;
    /** @var array<int, resource> */
    private array $pipes = [];
    private string $log;
    private string $buffer = '';

    protected function setUp(): void
    {
        $this->log = (string) tempnam(sys_get_temp_dir(), 'mcp-relay-');
        $this->open([PHP_BINARY, __DIR__ . '/fake-server.php', $this->log]);
    }

    /** @param list<string> $server the command the relay runs */
    private function open(array $server, array $env = []): void
    {
        $this->proc = proc_open(
            [PHP_BINARY, self::RELAY, ...$server],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes,
            null,
            $env === [] ? null : $env + getenv(),
        );
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->pipes[0])) {
            fclose($this->pipes[0]);
        }
        @proc_terminate($this->proc);
        @proc_close($this->proc);
        @unlink($this->log);
    }

    #[Test]
    public function a_server_that_dies_is_replaced_and_the_agent_keeps_working(): void
    {
        $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]);
        $first = $this->receive();
        self::assertSame(1, $first['id']);
        $this->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        $this->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        $before = $this->receive()['result'];
        $pid = $before['pid'];
        // The first start keeps bin/semitexa's fallbacks: with the flag, a
        // stopped app answered 75 and the server never started.
        self::assertSame('', $before['direct']);
        // REGRESSION: without it bin/semitexa started a relay under the
        // relay, and so on — a fork bomb on the host.
        self::assertSame('1', $before['relayed'], 'the first start says the relay is already there');

        // The container goes away mid-call.
        $this->send(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'test/die']);
        $failed = $this->receive();
        self::assertSame(3, $failed['id'], 'the call the dead server held is answered, not left hanging');
        self::assertSame(-32603, $failed['error']['code']);

        $changed = $this->receive(5.0);
        self::assertSame('notifications/tools/list_changed', $changed['method'] ?? null, 'the agent hears the tools may have changed — and never the replay answer');

        $this->send(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list']);
        $after = $this->receive();
        self::assertSame(4, $after['id']);
        self::assertNotSame($pid, $after['result']['pid'], 'answered by the new server');
        self::assertSame('1', $after['result']['direct'], 'a restart asks for the running app');
        self::assertSame('1', $after['result']['relayed']);

        $seen = (string) file_get_contents($this->log);
        $newServer = array_values(array_filter(explode("\n", $seen), static fn (string $l): bool => str_starts_with($l, $after['result']['pid'] . ' ')));
        self::assertStringContainsString('"method":"initialize"', $newServer[0] ?? '', 'the new server got the handshake first');
        self::assertStringContainsString('semitexa-relay-reinit-', $newServer[0] ?? '');
        self::assertStringContainsString('initialized"', $newServer[1] ?? '', 'then the initialized notification');
    }

    /** Replayed from the line as sent: decoded to an array, `{}` became `[]`. */
    #[Test]
    public function the_replayed_handshake_is_the_agents_own(): void
    {
        $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['capabilities' => ['sampling' => new \stdClass()]]]);
        $this->receive();
        $this->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'test/die']);
        $this->receive();
        $this->receive(5.0); // list_changed

        // Both servers log what they get: the first the agent's line, the
        // second the replay — so one `{}` alone would not prove the replay.
        $log = (string) file_get_contents($this->log);
        self::assertSame(2, substr_count($log, '"sampling":{}'), 'the replay keeps {} as sent: ' . $log);
        self::assertStringNotContainsString('"sampling":[]', $log);
    }

    /** `printf ... | bin/semitexa mcp`: the answers come before the relay ends. */
    #[Test]
    public function a_piped_one_shot_still_gets_its_answers(): void
    {
        $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
        $this->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        fclose($this->pipes[0]);

        self::assertSame(1, $this->receive()['id']);
        self::assertSame(2, $this->receive()['id']);

        $deadline = microtime(true) + 5.0;
        while (($status = proc_get_status($this->proc))['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        self::assertSame(0, $status['exitcode'], 'and ends cleanly: ' . stream_get_contents($this->pipes[2]));
    }

    /** Docker down for good: the relay gives up after its window instead of retrying forever. */
    #[Test]
    public function a_server_that_never_stays_up_is_given_up_on(): void
    {
        proc_terminate($this->proc);
        proc_close($this->proc);
        $this->open([PHP_BINARY, '-r', 'exit(75);'], ['SEMITEXA_MCP_RELAY_RETRY_SECONDS' => '2']);

        $deadline = microtime(true) + 10.0;
        while (($status = proc_get_status($this->proc))['running'] && microtime(true) < $deadline) {
            usleep(100_000);
        }
        self::assertFalse($status['running'], 'still retrying after 10 s with a 2 s window');
        self::assertSame(1, $status['exitcode']);
        self::assertStringContainsString('did not come back', (string) stream_get_contents($this->pipes[2]));
    }

    #[Test]
    public function the_relay_ends_when_the_agent_does(): void
    {
        $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        $this->receive();
        fclose($this->pipes[0]);

        $deadline = microtime(true) + 5.0;
        while (proc_get_status($this->proc)['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        self::assertFalse(proc_get_status($this->proc)['running']);
    }

    /** @param array<string, mixed> $message */
    private function send(array $message): void
    {
        fwrite($this->pipes[0], json_encode($message) . "\n");
        fflush($this->pipes[0]);
    }

    /** @return array<string, mixed> */
    private function receive(float $timeout = 3.0): array
    {
        $deadline = microtime(true) + $timeout;
        while (($nl = strpos($this->buffer, "\n")) === false) {
            if (microtime(true) > $deadline) {
                self::fail('no message from the relay within ' . $timeout . 's; stderr: ' . stream_get_contents($this->pipes[2]));
            }
            $chunk = fread($this->pipes[1], 8192);
            if ($chunk === '' || $chunk === false) {
                usleep(20_000);
                continue;
            }
            $this->buffer .= $chunk;
        }
        $line = substr($this->buffer, 0, $nl);
        $this->buffer = substr($this->buffer, $nl + 1);
        $decoded = json_decode($line, true);
        self::assertIsArray($decoded, 'not JSON: ' . $line);

        return $decoded;
    }
}
