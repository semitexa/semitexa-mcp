<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Application\Service;

use Semitexa\Mcp\Domain\Model\CommandToolSpec;

/**
 * The tools an agent building a Semitexa app gets (tk-mcp-curated-tools):
 * to know the project, to change it as the framework expects, and to check
 * the change. Few on purpose: every tool listed costs the agent context on
 * every turn, so a command earns a tool only when an agent needs it to build.
 * Generators come as one tool, semitexa_generate (CommandTools).
 */
final class SemitexaToolCatalog
{
    /** @return list<CommandToolSpec> */
    public static function specs(): array
    {
        return [
            // Know
            new CommandToolSpec(
                'semitexa_orient',
                'ai:orient',
                'Call it first: what this project is, what is being worked on, and the next step.',
                hidden: ['traces'],
            ),
            new CommandToolSpec(
                'semitexa_ask',
                'ai:ask',
                'Ask about this project and the framework instead of guessing: capabilities (what exists to build with), '
                    . 'mechanisms, project, module (with name), route (with path, method), event (with name), path (a file or directory), logs.',
            ),
            new CommandToolSpec(
                'semitexa_task',
                'ai:task',
                'Describe the task in a line: answers how this framework does it (a recipe) and the generator call that starts it.',
                fixed: ['--json'],
            ),
            new CommandToolSpec('semitexa_routes', 'routes:list', 'Every route of the app, with its payload, handler and access.'),
            new CommandToolSpec('semitexa_route', 'routes:show', 'One route in detail: by its path, or its index from semitexa_routes.'),
            new CommandToolSpec('semitexa_logs', 'logs:app', 'The app\'s logs, filtered: read them when something you changed does not behave.'),
            // Do
            new CommandToolSpec(
                'semitexa_scaffold',
                'make',
                'Run a recipe end to end (semitexa_task names it). Without write it only shows what it would create.',
                hidden: ['force', 'override-duplicate'],
                readOnly: false,
                idempotent: false,
                keyValueOption: 'arg',
                // make copies an --arg into every step: these would reach the flags hidden above.
                reservedKeys: ['write', 'force', 'override-duplicate', 'dry-run', 'json', 'ndjson', 'llm-hints', 'trace'],
            ),
            new CommandToolSpec(
                'semitexa_invoke',
                'ai:invoke',
                'Run a payload handler as a request to its route would, without HTTP: check that what you built answers. '
                    . 'preview: true says what would run without running it. It runs the handler, so what the handler writes is written.',
                readOnly: false,
                destructive: true,
                idempotent: false,
            ),
            // Check
            new CommandToolSpec(
                'semitexa_verify',
                'ai:verify',
                'Check what you changed before you call it done: the lints, tests and structure rules that apply to those files, '
                    . 'with a receipt. dirty: true checks every uncommitted change.',
                hidden: ['diff-stdin', 'list-e2e', 'all'],
                // It runs the tests and writes its receipt.
                readOnly: false,
                timeoutSeconds: 900,
            ),
            new CommandToolSpec('semitexa_doctor', 'system:doctor', 'Whether the environment this app runs in is set up as its packages need.'),
        ];
    }
}
