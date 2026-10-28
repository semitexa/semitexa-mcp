<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Domain\Model;

/** What a command did: its exit code and what it wrote to each stream. */
final readonly class CommandRun
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public bool $timedOut = false,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->exitCode === 0 && !$this->timedOut;
    }
}
