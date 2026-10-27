<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Mcp\Application\Service\LocalCommandRunner;

/** A tool's command runs in a process of its own: what it prints or how it exits stays in its result. */
final class LocalCommandRunnerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/semitexa-mcp-runner-' . uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir . '/console', <<<'PHP'
            <?php
            fwrite(STDOUT, json_encode(array_slice($argv, 1)) . "\n");
            fwrite(STDERR, getcwd());
            $cmd = $argv[1] ?? '';
            if ($cmd === 'sleep') { sleep(5); }
            if ($cmd === 'spawn') {
                // What ai:verify does: start a process of its own, then wait.
                $child = proc_open(['sleep', '30'], [], $pipes);
                file_put_contents(__DIR__ . '/child.pid', (string) proc_get_status($child)['pid']);
                sleep(30);
            }
            exit($cmd === 'fail' ? 3 : 0);
            PHP);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[Test]
    public function it_runs_the_console_with_the_command_and_its_arguments_in_the_project(): void
    {
        $run = (new LocalCommandRunner($this->dir . '/console', $this->dir))->run('ai:ask', ['route', '--path=/'], 10);

        self::assertTrue($run->succeeded());
        self::assertSame('["ai:ask","--no-interaction","route","--path=\/"]', trim($run->stdout));
        self::assertSame(realpath($this->dir), $run->stderr);
    }

    #[Test]
    public function an_exit_code_and_a_timeout_are_reported(): void
    {
        $runner = new LocalCommandRunner($this->dir . '/console', $this->dir);

        $failed = $runner->run('fail', [], 10);
        $slow = $runner->run('sleep', [], 1);

        self::assertSame([3, false], [$failed->exitCode, $failed->succeeded()]);
        self::assertSame([true, false], [$slow->timedOut, $slow->succeeded()]);
    }

    /** Killed past its time with what it started: a phpunit left running loads the box with nobody waiting (review). */
    #[Test]
    public function past_its_time_what_the_command_started_is_killed_too(): void
    {
        // The runner kills the group only with setsid and posix_kill, and the
        // child is seen only through procfs: without them an unreadable stat
        // read as "killed". Missing, the test fails instead of passing blind.
        self::assertTrue(is_executable('/usr/bin/setsid') && function_exists('posix_kill'), 'the group kill needs /usr/bin/setsid and posix_kill');
        self::assertNotFalse(@file_get_contents('/proc/self/stat'), 'the child is watched through procfs');
        $run = (new LocalCommandRunner($this->dir . '/console', $this->dir))->run('spawn', [], 1);
        $child = (int) file_get_contents($this->dir . '/child.pid');
        usleep(200_000);

        self::assertTrue($run->timedOut);
        self::assertGreaterThan(0, $child);
        // Gone, or dead and waiting for the container's init to reap it.
        $state = @file_get_contents("/proc/{$child}/stat");
        self::assertTrue($state === false || preg_match('/^\d+ \(.*\) Z/', $state) === 1, 'the child it started was killed: ' . $state);
    }
}
