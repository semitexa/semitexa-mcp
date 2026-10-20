<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Domain\Contract;

use Semitexa\Mcp\Domain\Model\McpResourceEntry;

/** Documents an agent may read: docs pages, the project's conventions, its routes. */
interface McpResourceProviderInterface
{
    /** @return list<McpResourceEntry> */
    public function resources(): array;

    /**
     * @return array{mimeType: string, text: string}|null the content; null when this provider has no such uri
     */
    public function read(string $uri): ?array;
}
