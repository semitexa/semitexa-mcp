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
