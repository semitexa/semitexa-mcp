<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Domain\Contract;

use Semitexa\Mcp\Domain\Model\McpPrompt;

/** Ready-made instructions an agent's user may start from: "add an HTML page", "add a module". */
interface McpPromptProviderInterface
{
    /** @return list<McpPrompt> */
    public function prompts(): array;

    /**
     * @param array<string, string> $arguments
     * @return list<array{role: 'user'|'assistant', text: string}>|null the messages; null when this provider has no such prompt
     */
    public function get(string $name, array $arguments): ?array;
}
