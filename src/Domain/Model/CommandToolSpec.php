<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Domain\Model;

/**
 * A tool that runs one bin/semitexa command. Its inputs are not written here:
 * they come from the command's own definition, so they cannot drift from it.
 * What is written here is judgement a definition does not hold: which options
 * an agent should not set (fixed is what the tool always passes), what the
 * call does to the project, and how long it may take.
 */
final readonly class CommandToolSpec
{
    /**
     * @param list<string> $fixed        arguments always passed, such as --json
     * @param list<string> $hidden       options an agent is not offered (besides fixed ones and the console's own)
     * @param list<string> $reservedKeys keys a key=value option (make's --arg) may not set: they would reach a hidden option
     */
    public function __construct(
        public string $tool,
        public string $command,
        public string $guidance,
        public array $fixed = ['--json'],
        public array $hidden = [],
        public bool $readOnly = true,
        public bool $destructive = false,
        public bool $idempotent = true,
        public int $timeoutSeconds = 120,
        public ?string $keyValueOption = null,
        public array $reservedKeys = [],
    ) {
    }
}
