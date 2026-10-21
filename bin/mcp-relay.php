<?php

declare(strict_types=1);

/*
 * Keeps a coding agent's MCP connection alive across `bin/semitexa
 * server:restart`.
 *
 * The server runs inside the app container (`docker compose exec ... mcp:serve`),
 * and a restart recreates that container: the server process died with it, the
 * agent saw "disconnected", and it does not restart a stdio server by itself —
 * its Semitexa tools were gone until someone reconnected by hand.
 *
 * This relay runs on the HOST (bin/semitexa starts it when PHP is there) and
 * holds the agent's stdio. It starts the real server as a child, passes lines
 * both ways, and remembers the agent's `initialize` request and
 * `notifications/initialized`. When the child ends while the agent is still
 * connected, every request still waiting gets an error answer, the relay starts
 * a new child (retrying while the stack comes back), replays the handshake under
 * an id of its own and drops that answer, then tells the agent the tool list may
 * have changed. Plain PHP, no autoloader: it runs where vendor/ was built for
 * the container.
 *
 *     php mcp-relay.php <command> [args...]    the command that starts the server
 */

final class SemitexaMcpRelay
{
    /** @var array{proc: resource, in: resource, out: resource}|null */
    private ?array $child = null;

    /** The agent's initialize request line, kept as sent: decoded to an array, `{}` came back `[]`. */
    private ?string $initialize = null;

    /** The agent's notifications/initialized line. */
    private ?string $initialized = null;

    /** @var array<string, mixed> request id (JSON) => id, sent to the current child and not answered */
    private array $pending = [];

    private ?string $replayId = null;
    private int $generation = 0;
    private string $clientBuffer = '';
    private string $childBuffer = '';
    /** When the server went away; null while one is serving. */
    private ?float $downSince = null;

    /** @var list<string> agent lines held until a replayed handshake is answered */
    private array $held = [];
    private bool $clientOpen = true;

    /** @param list<string> $command */
    public function __construct(private readonly array $command, private readonly int $retryForSeconds)
    {
    }

    public function run(): int
    {
        $this->child = $this->start();
        if ($this->child === null) {
            fwrite(STDERR, "semitexa mcp relay: could not start the server\n");

            return 1;
        }
        stream_set_blocking(STDIN, false);

        while (true) {
            $streams = $this->clientOpen ? [STDIN] : [];
            if ($this->child !== null) {
                $streams[] = $this->child['out'];
            }
            $write = $except = null;
            if ($streams !== [] && @stream_select($streams, $write, $except, 1) === false) {
                continue; // interrupted by a signal
            }
            foreach ($streams as $stream) {
                if ($stream === STDIN) {
                    $this->fromClient();
                } else {
                    $this->fromChild();
                }
            }

            if (!$this->clientOpen) {
                $this->drain();

                return 0;
            }
            if ($this->child === null && !$this->restart()) {
                fwrite(STDERR, "semitexa mcp relay: the server did not come back within {$this->retryForSeconds}s\n");

                return 1;
            }
        }
    }

    private function fromClient(): void
    {
        $chunk = fread(STDIN, 65536);
        if ($chunk === '' || $chunk === false) {
            if (feof(STDIN)) {
                $this->clientOpen = false;
            }

            return;
        }
        $this->clientBuffer .= $chunk;
        while (($line = self::takeLine($this->clientBuffer)) !== null) {
            $message = json_decode($line, true);
            $isRequest = is_array($message) && isset($message['method']) && array_key_exists('id', $message);
            if (is_array($message) && ($message['method'] ?? null) === 'initialize') {
                $this->initialize = $line;
            } elseif (is_array($message) && ($message['method'] ?? null) === 'notifications/initialized') {
                $this->initialized = $line;
            }

            if ($this->child === null) {
                // Between servers: answer at once rather than hold the call.
                if ($isRequest) {
                    $this->answerError($message['id'], 'The Semitexa MCP server is restarting. Try again in a moment.');
                }
                continue;
            }
            if ($isRequest) {
                $this->pending[(string) json_encode($message['id'])] = $message['id'];
            }
            if ($this->replayId !== null) {
                // The new server has not finished its handshake: nothing before it.
                $this->held[] = $line;
                continue;
            }
            @fwrite($this->child['in'], $line);
            @fflush($this->child['in']);
        }
    }

    private function fromChild(): void
    {
        if ($this->child === null) {
            return;
        }
        $chunk = fread($this->child['out'], 65536);
        if ($chunk !== '' && $chunk !== false) {
            $this->childBuffer .= $chunk;
            while (($line = self::takeLine($this->childBuffer)) !== null) {
                $this->deliver($line);
            }
        }
        if (feof($this->child['out'])) {
            $this->stop();
            // Calls the dead server will never answer: the agent must not wait forever.
            foreach ($this->pending as $id) {
                $this->answerError($id, 'The Semitexa MCP server restarted (the app container was recreated). Try the call again.');
            }
            $this->pending = [];
            $this->held = [];
            // From the FIRST death on: a server that dies as it starts must
            // not restart the clock, or the window never runs out.
            $this->downSince ??= microtime(true);
        }
    }

    private function deliver(string $line): void
    {
        // A server that answers is up: the retry window starts over next time.
        $this->downSince = null;
        $message = json_decode($line, true);
        if (is_array($message) && array_key_exists('id', $message) && !isset($message['method'])) {
            if ($this->replayId !== null && $message['id'] === $this->replayId) {
                // The answer to the relay's own replayed handshake.
                $this->replayId = null;
                if ($this->child !== null) {
                    foreach ([...($this->initialized !== null ? [$this->initialized] : []), ...$this->held] as $held) {
                        @fwrite($this->child['in'], $held);
                    }
                    @fflush($this->child['in']);
                }
                $this->held = [];
                $this->toClient(['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed']);

                return;
            }
            unset($this->pending[(string) json_encode($message['id'])]);
        }
        fwrite(STDOUT, $line);
        fflush(STDOUT);
    }

    /** @return bool false once the stack has been gone longer than the retry window */
    private function restart(): bool
    {
        if (microtime(true) - (float) $this->downSince > $this->retryForSeconds) {
            return false;
        }
        usleep(1_000_000);
        $this->child = $this->start();
        if ($this->child !== null && $this->initialize !== null) {
            $this->replayId = 'semitexa-relay-reinit-' . $this->generation;
            $replay = json_decode($this->initialize);
            if ($replay instanceof \stdClass) {
                $replay->id = $this->replayId;
                @fwrite($this->child['in'], json_encode($replay, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                @fflush($this->child['in']);
            }
        }

        return true;
    }

    /** @return array{proc: resource, in: resource, out: resource}|null */
    private function start(): ?array
    {
        $env = getenv();
        $env['SEMITEXA_MCP_DIRECT'] = '1';
        $pipes = [];
        $proc = proc_open($this->command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes, null, $env);
        if (!is_resource($proc)) {
            return null;
        }
        stream_set_blocking($pipes[1], false);
        $this->generation++;
        $this->childBuffer = '';

        return ['proc' => $proc, 'in' => $pipes[0], 'out' => $pipes[1]];
    }

    /**
     * The agent closed its side: let the server finish what it was asked
     * (a piped one-shot `printf ... | bin/semitexa mcp` waits for its answers),
     * then stop it.
     */
    private function drain(): void
    {
        if ($this->child === null) {
            return;
        }
        @fclose($this->child['in']);
        stream_set_blocking($this->child['out'], true);
        stream_set_timeout($this->child['out'], 30);
        while (($chunk = fgets($this->child['out'])) !== false) {
            $this->childBuffer .= $chunk;
            while (($line = self::takeLine($this->childBuffer)) !== null) {
                $this->deliver($line);
            }
        }
        $this->stop();
    }

    private function stop(): void
    {
        if ($this->child === null) {
            return;
        }
        foreach ([$this->child['in'], $this->child['out']] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe); // drain() may have closed stdin already
            }
        }
        @proc_terminate($this->child['proc']);
        @proc_close($this->child['proc']);
        $this->child = null;
        $this->replayId = null;
    }

    private function answerError(mixed $id, string $message): void
    {
        $this->toClient(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => $message]]);
    }

    /** @param array<string, mixed> $message */
    private function toClient(array $message): void
    {
        fwrite(STDOUT, self::encode($message));
        fflush(STDOUT);
    }

    /** @param array<string, mixed> $message */
    private static function encode(array $message): string
    {
        return json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }

    /** The first whole line of $buffer, taken out of it; null when there is none yet. */
    private static function takeLine(string &$buffer): ?string
    {
        $nl = strpos($buffer, "\n");
        if ($nl === false) {
            return null;
        }
        $line = substr($buffer, 0, $nl + 1);
        $buffer = substr($buffer, $nl + 1);

        return $line;
    }
}

// A warning on stdout would be read by the agent as a protocol message.
ini_set('display_errors', 'stderr');

if (PHP_SAPI !== 'cli' || $argc < 2) {
    fwrite(STDERR, "usage: php mcp-relay.php <server command> [args...]\n");
    exit(2);
}

exit((new SemitexaMcpRelay(array_slice($argv, 1), (int) (getenv('SEMITEXA_MCP_RELAY_RETRY_SECONDS') ?: 300)))->run());
