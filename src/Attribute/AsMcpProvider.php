<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Attribute;

use Attribute;

/**
 * Marks a class that gives the MCP server tools, resources or prompts. It
 * implements one or more of McpToolProviderInterface,
 * McpResourceProviderInterface and McpPromptProviderInterface, and is a
 * service (#[AsService]): it is built through the container, so it can read
 * its package's config and services.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsMcpProvider
{
}
