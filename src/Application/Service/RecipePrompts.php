<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Dev\Application\Service\Ai\Recipe\Recipe;
use Semitexa\Dev\Application\Service\Ai\Recipe\RecipeRegistry;
use Semitexa\Mcp\Attribute\AsMcpProvider;
use Semitexa\Mcp\Domain\Contract\McpPromptProviderInterface;
use Semitexa\Mcp\Domain\Model\McpPrompt;

/**
 * semitexa/dev's recipes as prompts (tk-mcp-prompts-resources): "add an HTML
 * page", "add a module", each with what it asks for. A prompt hands the agent
 * the way this framework builds the thing, with the tools to call and the
 * check to end on, so its user does not have to know them.
 */
#[AsService]
#[AsMcpProvider]
final class RecipePrompts implements McpPromptProviderInterface
{
    public function prompts(): array
    {
        return array_map(static fn (Recipe $recipe): McpPrompt => new McpPrompt(
            $recipe->id,
            $recipe->summary,
            array_merge(
                [['name' => 'goal', 'description' => 'What it is for, in your own words', 'required' => false]],
                array_map(
                    static fn (string $option, string $hint): array => ['name' => ltrim($option, '-'), 'description' => $hint, 'required' => false],
                    array_keys($recipe->arg_hints),
                    array_values($recipe->arg_hints),
                ),
            ),
            $recipe->label,
        ), self::recipes());
    }

    public function get(string $name, array $arguments): ?array
    {
        foreach (self::recipes() as $recipe) {
            if ($recipe->id === $name) {
                return [['role' => 'user', 'text' => self::instructions($recipe, $arguments)]];
            }
        }

        return null;
    }

    /** @param array<string, string> $arguments */
    private static function instructions(Recipe $recipe, array $arguments): string
    {
        $goal = trim($arguments['goal'] ?? '');
        unset($arguments['goal']);
        $given = array_filter($arguments, static fn (string $v): bool => trim($v) !== '');
        $hints = implode(', ', array_map(static fn (string $o): string => ltrim($o, '-'), array_keys($recipe->arg_hints)));
        $args = $given === []
            ? "Its arguments ({$hints}) go in arg as \"name=value\": choose them from the project (semitexa_ask), and ask me when you cannot tell."
            : 'Its arguments go in arg as "name=value": ' . implode(', ', array_map(static fn (string $k, string $v): string => "\"{$k}={$v}\"", array_keys($given), $given))
                . ($hints !== '' ? "; choose the others ({$hints}) from the project." : '.');

        return implode("\n", array_filter([
            "{$recipe->label}: {$recipe->summary}",
            $goal !== '' ? "It is for: {$goal}" : null,
            '',
            'Build it the way this Semitexa project does:',
            '1. semitexa_orient, to see the project and what is in progress.',
            "2. semitexa_scaffold with recipe: {$recipe->id}, without write, to see what it creates; then again with write: true. {$args}",
            '3. Fill in what the generated files leave to you. semitexa_ask and the docs resources say how this framework does it; do not guess.',
            '4. semitexa_invoke on the route, to see that it answers as intended.',
            '5. semitexa_verify on every file you changed, until it passes. Say what you built and give its receipt id.',
        ], static fn (?string $line): bool => $line !== null));
    }

    /** @return list<Recipe> */
    private static function recipes(): array
    {
        return array_values(RecipeRegistry::all());
    }
}
