<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Tests\Unit\Fixture;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/** A generator that names the options it refuses to run without, the way every make:* does. */
#[AsCommand(name: 'make:page', description: 'Makes a page')]
final class RequiringPageCommand extends Command
{
    public const REQUIRED_OPTIONS = ['module'];

    protected function configure(): void
    {
        $this
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Module')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Name')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dry')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Write')
            ->addOption('json', null, InputOption::VALUE_NONE, 'As JSON');
    }
}
