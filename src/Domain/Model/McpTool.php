<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Domain\Model;

/**
 * One tool as tools/list describes it. The hints tell a client what calling it
 * does, so it can ask the user first: read-only (asks nothing of the project),
 * destructive (changes or removes what is there), idempotent (a second call
 * with the same input changes nothing more).
 */
final readonly class McpTool
{
    /** @param array<string, mixed> $inputSchema a JSON Schema object */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema = ['type' => 'object', 'properties' => []],
        public ?string $title = null,
        public bool $readOnly = false,
        public bool $destructive = false,
        public bool $idempotent = false,
    ) {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) !== 1) {
            throw new \InvalidArgumentException("An MCP tool name is 1-64 letters, digits, _ or -: \"{$name}\".");
        }
    }

    /** @return list<string> */
    public function requiredInputs(): array
    {
        $required = $this->inputSchema['required'] ?? [];

        return is_array($required) ? array_values(array_filter($required, 'is_string')) : [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $schema = $this->inputSchema;
        if (($schema['properties'] ?? null) === []) {
            // An empty JSON object, not a list.
            $schema['properties'] = new \stdClass();
        }

        return array_filter([
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'inputSchema' => $schema,
            'annotations' => [
                'readOnlyHint' => $this->readOnly,
                'destructiveHint' => $this->destructive,
                'idempotentHint' => $this->idempotent,
                // The project, not the world: no tool reaches beyond it.
                'openWorldHint' => false,
            ],
        ], static fn (mixed $v): bool => $v !== null);
    }
}
