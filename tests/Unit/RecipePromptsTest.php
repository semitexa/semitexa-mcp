<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Mcp\Application\Service\RecipePrompts;
use Semitexa\Mcp\Domain\Model\McpPrompt;

/** semitexa/dev's recipes as prompts: what each asks for, and the way it is built and checked. */
final class RecipePromptsTest extends TestCase
{
    #[Test]
    public function each_recipe_is_a_prompt_asking_for_its_arguments_and_a_goal(): void
    {
        $page = $this->prompt('add_html_page');

        self::assertSame('Add HTML page', $page->title);
        self::assertSame(['goal', 'module', 'name', 'path', 'method'], array_column($page->arguments, 'name'));
        self::assertSame([false], array_values(array_unique(array_column($page->arguments, 'required'))));
    }

    #[Test]
    public function a_prompt_names_the_recipe_the_arguments_given_and_ends_on_a_check(): void
    {
        $messages = (new RecipePrompts())->get('add_html_page', ['goal' => 'a pricing page', 'path' => '/pricing', 'name' => '']);

        self::assertNotNull($messages);
        self::assertSame('user', $messages[0]['role']);
        $text = $messages[0]['text'];
        self::assertStringContainsString("It is for: a pricing page\n", $text);
        self::assertStringContainsString('semitexa_scaffold with recipe: add_html_page, without write', $text);
        self::assertStringContainsString('Its arguments go in arg as "name=value": "path=/pricing"; choose the others (module, name, path, method) from the project.', $text);
        self::assertStringEndsWith('5. semitexa_verify on every file you changed, until it passes. Say what you built and give its receipt id.', $text);
    }

    /** An audit ("findings, not code") was told to scaffold with write: true and invoke a route it never had. */
    #[Test]
    public function a_recipe_that_generates_nothing_is_not_scaffolded_or_invoked(): void
    {
        $messages = (new RecipePrompts())->get('audit_codebase', []);

        self::assertNotNull($messages);
        self::assertStringEndsWith(implode("\n", [
            'Build it the way this Semitexa project does:',
            '1. semitexa_orient, to see the project and what is in progress.',
            '2. Nothing is generated for this: read the code it concerns first. semitexa_ask and the docs resources say how this framework does it; do not guess.',
            '3. semitexa_verify on every file you changed, if any, until it passes. Say what you found or did, and give its receipt id.',
        ]), $messages[0]['text']);
    }

    /** A service has no route to invoke. */
    #[Test]
    public function a_generated_service_is_scaffolded_but_not_invoked(): void
    {
        $text = (new RecipePrompts())->get('add_service', [])[0]['text'] ?? '';

        self::assertStringContainsString("\n2. semitexa_scaffold with recipe: add_service, without write", $text);
        self::assertStringContainsString("\n4. semitexa_verify on every file you changed, until it passes.", $text);
        self::assertStringNotContainsString('semitexa_invoke', $text);
    }

    #[Test]
    public function an_unknown_prompt_is_none_of_this_providers(): void
    {
        self::assertNull((new RecipePrompts())->get('nope', []));
    }

    private function prompt(string $name): McpPrompt
    {
        foreach ((new RecipePrompts())->prompts() as $prompt) {
            if ($prompt->name === $name) {
                return $prompt;
            }
        }
        self::fail("no prompt {$name}");
    }
}
