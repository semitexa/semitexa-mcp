<?php

declare(strict_types=1);

namespace Semitexa\Mcp;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog. Nothing reads this at
 * runtime.
 */
#[Capability(
    id: 'mcp.server',
    summary: 'A Model Context Protocol server through which a coding agent (Claude Code, Cursor, Claude Desktop) learns the project, generates code the framework expects and checks what it changed.',
    useWhen: 'An AI agent builds or changes this Semitexa project and should use the framework\'s own tools and checks rather than guessing its conventions.',
    avoidWhen: 'Nobody works on the project through an agent, or outside dev: its tools change the project.',
    replaces: [
        'a hand-written MCP server wrapping bin/semitexa commands',
        'pasting framework conventions into every agent session',
    ],
)]
final class Capabilities
{
}
