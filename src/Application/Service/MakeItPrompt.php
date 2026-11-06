<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Mcp\Attribute\AsMcpProvider;
use Semitexa\Mcp\Domain\Contract\McpPromptProviderInterface;
use Semitexa\Mcp\Domain\Model\McpPrompt;

/**
 * "make it" for any MCP client (ep-make-it-for-every-agent), with no skill file
 * to install. What happens next is not in this text: it only sends the agent to
 * ai:make-it and back, so every client gets the answer every other agent gets.
 */
#[AsService]
#[AsMcpProvider]
final class MakeItPrompt implements McpPromptProviderInterface
{
    public const NAME = 'make-it';

    public function prompts(): array
    {
        return [new McpPrompt(
            self::NAME,
            'Work on this project until nothing is left: an interview on an empty backlog, the next approved task, ideas to offer, or all done.',
            [],
            'Make it',
        )];
    }

    public function get(string $name, array $arguments): ?array
    {
        if ($name !== self::NAME) {
            return null;
        }

        return [['role' => 'user', 'text' => implode("\n", [
            'Make it. What to do next is Semitexa\'s answer, not yours to work out:',
            '1. Call semitexa_make_it.',
            '2. Tell me what say says, in my language, and do the steps. They are bin/semitexa commands, for your shell. With no shell you can still record my answers with semitexa_charter; for the rest, tell me and stop.',
            '3. While go_on is true, go back to 1. When it is false, finish its steps and stop; go back to 1 only if a step says so.',
            '',
            'Do not take a task it did not name, and do not approve anything yourself: that is my word.',
        ])]];
    }
}
