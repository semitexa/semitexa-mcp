<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Mcp\Attribute\AsMcpProvider;
use Semitexa\Mcp\Domain\Contract\McpResourceProviderInterface;
use Semitexa\Mcp\Domain\Model\McpResourceEntry;

/**
 * What an agent should read before it writes (tk-mcp-prompts-resources): the
 * project's AGENTS.md, and the framework's docs pages that semitexa/docs
 * installs, each by the id its front matter gives. A page is read only
 * through that index, so a uri cannot name a file outside it.
 */
#[AsService]
#[AsMcpProvider]
final class ProjectDocs implements McpResourceProviderInterface
{
    public const AGENTS = 'semitexa://project/AGENTS.md';
    public const DOCS = 'semitexa://docs/';

    /** @var array<string, array{file: string, title: string, summary: string}>|null uri => page */
    private ?array $pages = null;

    private ?string $root = null;

    /** For a project other than the one running (tests). */
    public function inProject(string $root): self
    {
        $docs = clone $this;
        $docs->root = $root;
        $docs->pages = null;

        return $docs;
    }

    public function resources(): array
    {
        $resources = [];
        if (is_file($this->root() . '/AGENTS.md')) {
            $resources[] = new McpResourceEntry(self::AGENTS, 'AGENTS.md', description: 'How work is done in this project: read it first.');
        }
        foreach ($this->pages() as $uri => $page) {
            $resources[] = new McpResourceEntry($uri, $page['title'], description: $page['summary'] !== '' ? $page['summary'] : null);
        }

        return $resources;
    }

    public function read(string $uri): ?array
    {
        $file = match (true) {
            $uri === self::AGENTS => $this->root() . '/AGENTS.md',
            default => $this->pages()[$uri]['file'] ?? null,
        };
        if ($file === null || !is_file($file)) {
            return null;
        }

        return ['mimeType' => 'text/markdown', 'text' => (string) file_get_contents($file)];
    }

    /** @return array<string, array{file: string, title: string, summary: string}> */
    private function pages(): array
    {
        if ($this->pages !== null) {
            return $this->pages;
        }
        $dir = $this->root() . '/vendor/semitexa/docs/docs/en';
        $pages = [];
        if (is_dir($dir)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'md') {
                    continue;
                }
                $meta = self::frontMatter((string) file_get_contents($file->getPathname(), length: 4096));
                if (($meta['id'] ?? '') === '' || in_array($meta['status'] ?? '', ['draft', 'archived'], true)) {
                    continue;
                }
                $pages[self::DOCS . $meta['id']] = [
                    'file' => $file->getPathname(),
                    'title' => $meta['title'] ?? $meta['id'],
                    'summary' => $meta['summary'] ?? '',
                ];
            }
        }
        ksort($pages);

        return $this->pages = $pages;
    }

    /** @return array<string, string> the scalar keys of a page's front matter */
    private static function frontMatter(string $head): array
    {
        if (preg_match('/\A---\R(.*?)\R---/s', $head, $m) !== 1) {
            return [];
        }
        $meta = [];
        foreach (preg_split('/\R/', $m[1]) ?: [] as $line) {
            if (preg_match('/^([a-z_]+):\s*(.+)$/', $line, $kv) === 1) {
                $meta[$kv[1]] = trim($kv[2], " \"'");
            }
        }

        return $meta;
    }

    private function root(): string
    {
        return $this->root ?? ProjectRoot::get();
    }
}
