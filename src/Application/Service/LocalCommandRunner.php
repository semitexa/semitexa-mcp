<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Application\Service;

use Semitexa\Mcp\Domain\Contract\CommandRunnerInterface;
use Semitexa\Mcp\Domain\Model\CommandRun;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs a command in a process of its own, in this project, with the same
 * console the server was started with. A process of its own: a command that
 * prints, exits or leaves state behind cannot reach the server's stream or
 * the next call. And a process group of its own where setsid exists: past
 * its time the whole group is killed, not only the console, or what it
 * started (ai:verify's phpunit) runs on with nobody waiting.
 */
final class LocalCommandRunner implements CommandRunnerInterface
{
    private const SETSID = '/usr/bin/setsid';

    /** @param string $console the bin/semitexa script this server runs as */
    public function __construct(
        private readonly string $console,
        private readonly string $workingDirectory,
    ) {
    }

    public function run(string $command, array $arguments, int $timeoutSeconds): CommandRun
    {
        // --no-interaction first: the arguments may end in "--" and positional values.
        $line = array_merge([PHP_BINARY, $this->console, $command, '--no-interaction'], $arguments);
        $grouped = is_executable(self::SETSID) && function_exists('posix_kill');
        $process = new Process($grouped ? array_merge([self::SETSID], $line) : $line, $this->workingDirectory);
        $process->setTimeout($timeoutSeconds);
        $process->start();
        // Now: once it has timed out the process forgets its pid.
        $pid = $process->getPid();
        try {
            $process->wait();
        } catch (ProcessTimedOutException) {
            if ($grouped && $pid !== null) {
                // setsid made the console the leader of a group with its own id.
                posix_kill(-$pid, SIGKILL);
            }

            return new CommandRun(124, $process->getOutput(), $process->getErrorOutput(), timedOut: true);
        }

        return new CommandRun((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput());
    }
}
