<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Domain\Contract;

use Semitexa\Mcp\Domain\Model\CommandRun;

/**
 * Where a tool's bin/semitexa command runs: this project's own container
 * (local), or, later, a sandbox of its own on a host that serves many. The
 * tools and their schemas are the same either way.
 */
interface CommandRunnerInterface
{
    /** @param list<string> $arguments as they follow the command on a command line */
    public function run(string $command, array $arguments, int $timeoutSeconds): CommandRun;
}
