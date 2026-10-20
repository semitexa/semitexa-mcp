<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Domain\Model;

/** One prompt as prompts/list describes it. */
final readonly class McpPrompt
{
    /** @param list<array{name: string, description: string, required: bool}> $arguments */
    public function __construct(
        public string $name,
        public string $description,
        public array $arguments = [],
        public ?string $title = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'arguments' => $this->arguments,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
