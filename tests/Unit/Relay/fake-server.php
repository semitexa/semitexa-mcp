<?php

// A stand-in MCP server for McpRelayTest: answers every request with its own
// pid, logs what it received, and exits on `test/die` without answering.
$log = $argv[1];
while (($line = fgets(STDIN)) !== false) {
    file_put_contents($log, getmypid() . ' ' . $line, FILE_APPEND);
    $message = json_decode($line, true);
    if (!is_array($message) || !array_key_exists('id', $message)) {
        continue;
    }
    if (($message['method'] ?? '') === 'test/die') {
        exit(0);
    }
    echo json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => ['pid' => getmypid(), 'method' => $message['method'] ?? null, 'direct' => (string) getenv('SEMITEXA_MCP_DIRECT'), 'relayed' => (string) getenv('SEMITEXA_MCP_RELAYED')]]), "\n";
    fflush(STDOUT);
}
