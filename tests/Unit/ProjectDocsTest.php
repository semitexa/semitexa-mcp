<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Mcp\Application\Service\ProjectDocs;
use Semitexa\Mcp\Domain\Model\McpResourceEntry;

/**
 * What an agent reads before it writes: the project's AGENTS.md and the
 * installed docs pages, each by its id, and nothing a uri could name besides.
 */
final class ProjectDocsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-mcp-docs-' . uniqid();
        mkdir($this->root . '/vendor/semitexa/docs/docs/en/events', 0o755, true);
        file_put_contents($this->root . '/AGENTS.md', '# Agents');
        file_put_contents($this->root . '/.env', 'DB_PASSWORD=secret');
        file_put_contents($this->root . '/vendor/semitexa/docs/docs/en/events/deferred.md', "---\nid: events/deferred\ntitle: Deferred Handler\nsummary: Heavy work after the response.\nstatus: published\n---\n# Deferred");
        file_put_contents($this->root . '/vendor/semitexa/docs/docs/en/events/draft.md', "---\nid: events/draft\ntitle: Draft\nstatus: draft\n---\n# Draft");
        file_put_contents($this->root . '/vendor/semitexa/docs/docs/en/events/no-front-matter.md', '# Loose');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function it_lists_agents_md_and_each_published_page_by_its_id(): void
    {
        $listed = array_map(static fn (McpResourceEntry $r): array => $r->toArray(), $this->docs()->resources());

        self::assertSame([
            ['uri' => 'semitexa://project/AGENTS.md', 'name' => 'AGENTS.md', 'mimeType' => 'text/markdown', 'description' => 'How work is done in this project: read it first.'],
            ['uri' => 'semitexa://docs/events/deferred', 'name' => 'Deferred Handler', 'mimeType' => 'text/markdown', 'description' => 'Heavy work after the response.'],
        ], $listed);
    }

    #[Test]
    public function a_listed_page_is_read_and_a_uri_outside_the_index_is_not(): void
    {
        $docs = $this->docs();

        self::assertSame(['mimeType' => 'text/markdown', 'text' => '# Agents'], $docs->read('semitexa://project/AGENTS.md'));
        self::assertStringEndsWith('# Deferred', $docs->read('semitexa://docs/events/deferred')['text'] ?? '');
        self::assertNull($docs->read('semitexa://docs/events/draft'));
        self::assertNull($docs->read('semitexa://docs/../../../../.env'));
        self::assertNull($docs->read('semitexa://project/.env'));
    }

    /** A listed file that cannot be read went out as an empty page, read successfully. */
    #[Test]
    public function a_listed_file_that_cannot_be_read_is_an_error_not_an_empty_page(): void
    {
        chmod($this->root . '/AGENTS.md', 0o000);
        // Root reads it whatever its permissions: then this proves nothing, and says so.
        self::assertFalse(is_readable($this->root . '/AGENTS.md'), 'the file must be unreadable to the user running the test');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AGENTS.md is listed but cannot be read.');

        $this->docs()->read('semitexa://project/AGENTS.md');
    }

    private function docs(): ProjectDocs
    {
        return (new ProjectDocs())->inProject($this->root);
    }
}
