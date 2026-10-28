<?php

declare(strict_types=1);

/**
 * The phase-1 proof (tk-mcp-e2e-proof): an agent with nothing but the MCP
 * tools builds a page and gets it checked. No LLM: the calls an agent would
 * make, in order, against a real mcp:serve, each answer read as an agent
 * reads it. Leaves nothing behind: the module it creates is removed.
 *
 * Run in the app container, from the project root, with APP_ENV=dev:
 *     php vendor/semitexa/mcp/tests/E2e/build-a-page.php
 * Prints one line per step; exits non-zero on the first that fails.
 */

$root = getcwd();
$module = 'McpProof' . substr(bin2hex(random_bytes(3)), 0, 5);
$path = '/' . strtolower($module) . '/pricing';
$moduleDir = "{$root}/src/modules/{$module}";

$server = proc_open([PHP_BINARY, 'vendor/bin/semitexa', 'mcp:serve'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root);
if (!is_resource($server)) {
    fwrite(STDERR, "could not start mcp:serve\n");
    exit(1);
}
$id = 0;
$call = static function (string $method, array $params = []) use (&$id, $pipes): array {
    $id++;
    fwrite($pipes[0], json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params]) . "\n");
    $line = fgets($pipes[1]);
    $answer = $line === false ? null : json_decode($line, true);
    if (!is_array($answer) || ($answer['id'] ?? null) !== $id) {
        throw new RuntimeException("{$method}: no answer");
    }

    return $answer;
};
$tool = static function (string $name, array $arguments) use ($call): array {
    $result = $call('tools/call', ['name' => $name, 'arguments' => (object) $arguments])['result'] ?? [];
    $text = $result['content'][0]['text'] ?? '';
    $value = json_decode($text, true);

    return ['error' => (bool) ($result['isError'] ?? false), 'text' => $text, 'value' => $value];
};
$steps = 0;
$step = static function (string $what, bool $ok, string $detail = '') use (&$steps): void {
    $steps++;
    printf("%2d %s %s%s\n", $steps, $ok ? 'ok  ' : 'FAIL', $what, $detail !== '' ? " ({$detail})" : '');
    if (!$ok) {
        throw new RuntimeException($what);
    }
};
$removeModule = static function () use ($moduleDir): void {
    if (!is_dir($moduleDir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($moduleDir);
};

$started = microtime(true);
$exit = 0;
try {
    $init = $call('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'e2e-proof', 'version' => '1']]);
    $step('initialize', ($init['result']['protocolVersion'] ?? '') === '2025-06-18');
    $tools = array_column($call('tools/list')['result']['tools'] ?? [], 'name');
    $step('tools/list', count(array_intersect(['semitexa_orient', 'semitexa_task', 'semitexa_generate', 'semitexa_invoke', 'semitexa_verify'], $tools)) === 5, count($tools) . ' tools');

    $orient = $tool('semitexa_orient', []);
    $step('semitexa_orient', !$orient['error']);

    $task = $tool('semitexa_task', ['description' => 'add an HTML page showing our pricing plans']);
    $step('semitexa_task names the recipe', ($task['value']['recipe'] ?? '') === 'add_html_page', (string) ($task['value']['recipe'] ?? $task['text']));

    $made = $tool('semitexa_generate', ['generator' => 'make:module', 'options' => ['name' => $module], 'write' => true]);
    $step('semitexa_generate make:module', !$made['error'] && is_dir($moduleDir), $module);

    $page = ['module' => $module, 'name' => 'Pricing', 'path' => $path, 'method' => 'GET'];
    // The whole module tree before and after, not one file the dry run
    // might have skipped while writing another: every directory too (an
    // empty one is still a write), and each file by its contents (a file
    // rewritten in place keeps its name). A file it cannot read stops the
    // run: hashed as '' it would match itself before and after, unchecked.
    $listing = static function () use ($moduleDir): array {
        $paths = [];
        $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($tree as $file) {
            if ($file instanceof SplFileInfo) {
                $digest = $file->isDir() ? 'dir' : sha1_file($file->getPathname());
                if ($digest === false) {
                    throw new RuntimeException("Cannot read {$file->getPathname()}");
                }
                $paths[$file->getPathname()] = $digest;
            }
        }
        ksort($paths);

        return $paths;
    };
    $before = $listing();
    $dry = $tool('semitexa_generate', ['generator' => 'make:page', 'options' => $page]);
    $step('semitexa_generate make:page, dry', !$dry['error'] && $before !== [] && $listing() === $before, 'nothing written');

    $written = $tool('semitexa_generate', ['generator' => 'make:page', 'options' => $page, 'write' => true]);
    $created = array_values(array_filter(array_map(
        static fn (mixed $c): string => is_array($c) ? (string) ($c['path'] ?? '') : (string) $c,
        $written['value']['result']['created'] ?? [],
    )));
    $step('semitexa_generate make:page, written', !$written['error'] && $created !== [], count($created) . ' files');

    $invoked = $tool('semitexa_invoke', ['route' => $path, 'method' => 'GET']);
    $step('semitexa_invoke the new route', !$invoked['error'], substr(str_replace("\n", ' ', $invoked['text']), 0, 80));

    $files = array_map(static fn (string $f): string => ltrim(str_replace($root, '', $f), '/'), $created);
    $verified = $tool('semitexa_verify', ['files' => $files]);
    $receipt = $verified['value']['receipt']['id'] ?? '';
    $step('semitexa_verify the new files', !$verified['error'] && ($verified['value']['verdict'] ?? '') === 'pass', (string) ($verified['value']['verdict'] ?? 'no verdict') . ($receipt !== '' ? ", receipt {$receipt}" : ''));
} catch (Throwable $e) {
    $exit = 1;
    fwrite(STDERR, $e->getMessage() . "\n");
} finally {
    fclose($pipes[0]);
    proc_close($server);
    $removeModule();
    printf("   %s in %.1f s; module %s removed\n", $exit === 0 ? 'PASS' : 'FAIL', microtime(true) - $started, $module);
}
exit($exit);
