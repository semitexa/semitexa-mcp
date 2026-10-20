<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Domain\Model;

/**
 * What a tool answers: text an agent reads, and whether it is a failure the
 * agent should act on (bad input, a check that did not pass). Failures of a
 * tool are results, not protocol errors, so the agent sees them.
 */
final readonly class McpToolResult
{
    /** @param list<array<string, mixed>> $content MCP content blocks */
    private function __construct(
        public array $content,
        public bool $isError,
        public mixed $structured = null,
    ) {
    }

    public static function text(string $text): self
    {
        return new self([['type' => 'text', 'text' => $text]], false);
    }

    /** A value an agent reads as JSON, also given as structured content. */
    public static function json(mixed $value): self
    {
        return new self([['type' => 'text', 'text' => self::encode($value)]], false, $value);
    }

    public static function error(string $message, mixed $detail = null): self
    {
        $text = $detail === null ? $message : $message . "\n" . self::encode($detail);

        return new self([['type' => 'text', 'text' => $text]], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = ['content' => $this->content, 'isError' => $this->isError];
        if (is_array($this->structured) && !array_is_list($this->structured)) {
            $result['structuredContent'] = $this->structured;
        }

        return $result;
    }

    private static function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
