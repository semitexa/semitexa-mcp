<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Mcp\Application\Service\MakeItPrompt;
use Semitexa\Mcp\Application\Service\SemitexaToolCatalog;

final class MakeItPromptTest extends TestCase
{
    #[Test]
    public function it_is_listed_with_no_arguments_to_fill(): void
    {
        $prompts = (new MakeItPrompt())->prompts();

        self::assertCount(1, $prompts);
        self::assertSame('make-it', $prompts[0]->name);
        self::assertSame([], $prompts[0]->arguments);
    }

    #[Test]
    public function it_sends_the_agent_to_the_tool_and_back_until_go_on_is_false(): void
    {
        $messages = (new MakeItPrompt())->get('make-it', []);

        self::assertNotNull($messages);
        self::assertSame('user', $messages[0]['role']);
        self::assertStringContainsString('semitexa_make_it', $messages[0]['text']);
        self::assertStringContainsString('While go_on is true', $messages[0]['text']);
        self::assertStringContainsString('When it is false, finish its steps and stop', $messages[0]['text']);
    }

    #[Test]
    public function it_answers_only_to_its_own_name(): void
    {
        self::assertNull((new MakeItPrompt())->get('add_html_page', []));
    }

    #[Test]
    public function the_tool_it_names_runs_the_command(): void
    {
        $specs = array_values(array_filter(SemitexaToolCatalog::specs(), static fn ($s): bool => $s->tool === 'semitexa_make_it'));

        self::assertCount(1, $specs);
        self::assertSame('ai:make-it', $specs[0]->command);
    }
}
