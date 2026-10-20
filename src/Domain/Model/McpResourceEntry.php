<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Domain\Model;

/** One document as resources/list describes it. */
final readonly class McpResourceEntry
{
    public function __construct(
        public string $uri,
        public string $name,
        public string $mimeType = 'text/markdown',
        public ?string $description = null,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter([
            'uri' => $this->uri,
            'name' => $this->name,
            'mimeType' => $this->mimeType,
            'description' => $this->description,
        ], static fn (?string $v): bool => $v !== null);
    }
}
