<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Domain\Contract;

use Semitexa\Mcp\Domain\Model\McpTool;
use Semitexa\Mcp\Domain\Model\McpToolResult;

/**
 * Tools an agent may call. A name is unique across every provider: the first
 * provider to name it keeps it, in class-name order, and the rest are reported.
 */
interface McpToolProviderInterface
{
    /** @return list<McpTool> */
    public function tools(): array;

    /**
     * Runs one of this provider's tools. A failure the agent should read (bad
     * input, a check that did not pass) is an McpToolResult::error(); a
     * throwable is reported to the agent as one too.
     *
     * @param array<string, mixed> $arguments as the agent sent them, checked against the required inputs
     */
    public function call(string $name, array $arguments): McpToolResult;
}
