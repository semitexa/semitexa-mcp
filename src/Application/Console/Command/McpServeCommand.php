<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Environment;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Mcp\Application\Service\CommandTools;
use Semitexa\Mcp\Application\Service\LocalCommandRunner;
use Semitexa\Mcp\Application\Service\McpProviders;
use Semitexa\Mcp\Application\Service\SemitexaToolCatalog;
use Semitexa\Mcp\Application\Service\McpServer;
use Semitexa\Mcp\Application\Service\StdioTransport;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * An MCP server on stdio, for a coding agent working on this project: the
 * tools, resources and prompts the installed packages provide. Dev only: its
 * tools change the project.
 *
 *     {"mcpServers": {"semitexa": {"command": "bin/semitexa", "args": ["mcp:serve"]}}}
 */
#[AsCommand(name: 'mcp:serve', description: 'MCP server on stdio: the tools, resources and prompts the installed packages provide, for a coding agent (dev only)')]
final class McpServeCommand extends BaseCommand
{
    public const INSTRUCTIONS = 'Tools for building a Semitexa app: learn the project and its conventions, '
        . 'generate code the framework expects, and check what you changed. Check before you report a change as done.';

    #[InjectAsReadonly]
    protected McpProviders $providers;

    protected function configure(): void
    {
        $this->setName('mcp:serve')
            ->setDescription('MCP server on stdio: the tools, resources and prompts the installed packages provide, for a coding agent (dev only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = static function (string $line): void {
            fwrite(STDERR, "[semitexa mcp] {$line}\n");
        };
        if (Environment::getEnvValue('APP_ENV', 'prod') !== 'dev') {
            $report('Refused: APP_ENV is not dev. These tools change the project.');

            return self::FAILURE;
        }

        $console = $this->getApplication();
        $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: (realpath(ProjectRoot::get() . '/vendor/bin/semitexa') ?: '');
        $providers = $this->providers->all($report);
        if ($console !== null && $script !== '') {
            // First, so a package cannot take one of these names.
            array_unshift($providers, new CommandTools($console, new LocalCommandRunner($script, ProjectRoot::get()), SemitexaToolCatalog::specs()));
        } else {
            $report('The bin/semitexa tools are left out: the console that started this server is not known.');
        }
        $server = new McpServer($providers, 'semitexa', '1', self::INSTRUCTIONS, $report);
        (new StdioTransport())->run($server, STDIN, STDOUT);

        return self::SUCCESS;
    }
}
