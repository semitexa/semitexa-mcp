<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Mcp\Application\Service\CommandTools;
use Semitexa\Mcp\Domain\Contract\CommandRunnerInterface;
use Semitexa\Mcp\Domain\Model\CommandRun;
use Semitexa\Mcp\Domain\Model\CommandToolSpec;
use Semitexa\Mcp\Domain\Model\McpTool;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Tools over bin/semitexa commands (tk-mcp-curated-tools): inputs taken from
 * the command's own definition, what an agent may not set kept out, and the
 * command's output handed back as the agent reads it.
 */
final class CommandToolsTest extends TestCase
{
    private ProbeRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new ProbeRunner();
    }

    #[Test]
    public function a_tool_offers_the_commands_own_inputs_without_output_control(): void
    {
        $tool = $this->tool('probe_ask');

        self::assertSame(['subject', 'name', 'files', 'list'], array_keys($tool->inputSchema['properties']));
        self::assertSame(['subject'], $tool->inputSchema['required']);
        self::assertSame(['type' => 'boolean', 'description' => 'List them'], $tool->inputSchema['properties']['list']);
        self::assertSame(['type' => 'array', 'items' => ['type' => ['string', 'number']], 'description' => 'Paths'], $tool->inputSchema['properties']['files']);
        self::assertSame("Answers questions.\n\nAsk instead of guessing.", $tool->description);
        self::assertTrue($tool->readOnly);
    }

    #[Test]
    public function a_command_that_is_not_installed_gives_no_tool(): void
    {
        $names = array_map(static fn (McpTool $t): string => $t->name, $this->tools()->tools());

        self::assertSame(['probe_ask', 'probe_make', 'semitexa_generate'], $names);
    }

    #[Test]
    public function a_call_becomes_the_command_line_with_the_fixed_arguments(): void
    {
        $this->runner->next = new CommandRun(0, '{"answer":42}', '');

        $result = $this->tools()->call('probe_ask', ['subject' => 'route', 'name' => 'Blog', 'files' => ['a.php', 'b.php'], 'list' => true]);

        self::assertSame(['probe:ask', ['--name=Blog', '--files=a.php', '--files=b.php', '--list', '--json', '--', 'route'], 120], $this->runner->calls[0]);
        self::assertSame(['answer' => 42], $result->structured);
        self::assertFalse($result->isError);
    }

    /** Output control, or overwriting, is the tool's decision: an input outside the schema is refused, not passed on. */
    #[Test]
    public function an_input_the_tool_does_not_offer_is_refused_before_anything_runs(): void
    {
        $result = $this->tools()->call('probe_ask', ['subject' => 'x', 'json' => false]);

        self::assertTrue($result->isError);
        self::assertSame('"json" is not an input of this tool.', $result->content[0]['text']);
        self::assertSame([], $this->runner->calls);
    }

    /** "--trace=abc" as a description set the hidden trace option; after "--" it is the description. */
    #[Test]
    public function a_positional_value_that_starts_with_a_dash_stays_a_value(): void
    {
        $this->tools()->call('probe_ask', ['subject' => '--trace=abc']);

        self::assertSame(['--json', '--', '--trace=abc'], $this->runner->calls[0][1]);
    }

    #[Test]
    public function a_flag_is_true_or_false_and_anything_else_is_refused(): void
    {
        $tools = $this->tools();
        $tools->call('probe_ask', ['subject' => 's', 'list' => false]);
        $refused = $tools->call('probe_ask', ['subject' => 's', 'list' => 'yes']);

        self::assertSame(['--json', '--', 's'], $this->runner->calls[0][1]);
        self::assertSame('"list" is true or false.', $refused->content[0]['text']);
        self::assertCount(1, $this->runner->calls);
    }

    /** make copies an --arg into every step: arg "force=1" was --force behind the hidden flag (review). */
    #[Test]
    public function a_key_value_option_cannot_set_a_reserved_key(): void
    {
        $result = $this->tools()->call('probe_make', ['recipe' => 'add_html_page', 'arg' => ['module=Blog', ' Force =1']]);

        self::assertSame('arg cannot set "force": this tool decides it.', $result->content[0]['text']);
        self::assertSame([], $this->runner->calls);
    }

    #[Test]
    public function line_delimited_json_is_one_list_and_text_stays_text(): void
    {
        $tools = $this->tools();
        $this->runner->next = new CommandRun(0, "{\"step\":1}\n{\"step\":2}\n", '');
        $lines = $tools->call('probe_ask', ['subject' => 's']);
        $this->runner->next = new CommandRun(0, "Plain words\nover lines\n", '');
        $text = $tools->call('probe_ask', ['subject' => 's']);

        self::assertSame("[\n    {\n        \"step\": 1\n    },\n    {\n        \"step\": 2\n    }\n]", $lines->content[0]['text']);
        self::assertSame('Plain words' . "\n" . 'over lines', $text->content[0]['text']);
    }

    /** ai:verify exits non-zero on a failed check: the agent must read which, not just that it failed. */
    #[Test]
    public function a_command_that_failed_is_a_failure_with_what_it_said(): void
    {
        $this->runner->next = new CommandRun(1, '{"verdict":"fail"}', "a warning\n");

        $result = $this->tools()->call('probe_ask', ['subject' => 's']);

        self::assertTrue($result->isError);
        self::assertSame("probe:ask exited with 1.\n{\n    \"output\": {\n        \"verdict\": \"fail\"\n    },\n    \"stderr\": \"a warning\"\n}", $result->content[0]['text']);
    }

    #[Test]
    public function a_long_answer_is_cut_and_says_so(): void
    {
        $this->runner->next = new CommandRun(0, str_repeat('x', CommandTools::MAX_TEXT + 10), '');

        $text = $this->tools()->call('probe_ask', ['subject' => 's'])->content[0]['text'];

        self::assertSame(CommandTools::MAX_TEXT, strpos($text, "\n… cut: 10 more characters."));
    }

    #[Test]
    public function the_generator_tool_lists_each_generators_options_without_the_overwriting_ones(): void
    {
        $tool = $this->tool('semitexa_generate');

        self::assertSame(['make:page'], $tool->inputSchema['properties']['generator']['enum']);
        self::assertStringContainsString("- make:page: Makes a page. Options: module, name.", $tool->description);
        self::assertSame(['generator'], $tool->inputSchema['required']);
    }

    /** It shows what it would create unless told to write, and never overwrites. */
    #[Test]
    public function a_generator_runs_dry_unless_told_to_write_and_refuses_force(): void
    {
        $tools = $this->tools();
        $this->runner->next = new CommandRun(0, '{}', '');
        $tools->call('semitexa_generate', ['generator' => 'make:page', 'options' => ['module' => 'Blog']]);
        $tools->call('semitexa_generate', ['generator' => 'make:page', 'options' => ['module' => 'Blog'], 'write' => true]);
        $forced = $tools->call('semitexa_generate', ['generator' => 'make:page', 'options' => ['force' => true], 'write' => true]);
        $unknown = $tools->call('semitexa_generate', ['generator' => 'make:nothing']);
        $beside = $tools->call('semitexa_generate', ['generator' => 'make:page', 'force' => true]);
        $notBool = $tools->call('semitexa_generate', ['generator' => 'make:page', 'write' => 'true']);

        self::assertSame([['make:page', ['--module=Blog', '--dry-run', '--json'], 120], ['make:page', ['--module=Blog', '--write', '--json'], 120]], $this->runner->calls);
        self::assertSame('"force" is not an input of this tool.', $forced->content[0]['text']);
        self::assertSame('No generator "make:nothing". One of: make:page.', $unknown->content[0]['text']);
        self::assertSame('"force" is not an input of this tool: a generator\'s options go in options.', $beside->content[0]['text']);
        self::assertSame('write is true or false.', $notBool->content[0]['text']);
    }

    private function tool(string $name): McpTool
    {
        foreach ($this->tools()->tools() as $tool) {
            if ($tool->name === $name) {
                return $tool;
            }
        }
        self::fail("no tool {$name}");
    }

    private function tools(): CommandTools
    {
        $console = new Application();
        $console->add((new Command('probe:ask'))
            ->setDescription('Answers questions.')
            ->addArgument('subject', InputArgument::REQUIRED, 'What about')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'A name')
            ->addOption('files', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Paths')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List them')
            ->addOption('json', null, InputOption::VALUE_NONE, 'As JSON')
            ->addOption('trace', null, InputOption::VALUE_REQUIRED, 'A trace id'));
        $console->add((new Command('probe:make'))
            ->setDescription('Runs a recipe.')
            ->addOption('recipe', null, InputOption::VALUE_REQUIRED, 'Recipe')
            ->addOption('arg', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'key=value')
            ->addOption('json', null, InputOption::VALUE_NONE, 'As JSON'));
        $console->add((new Command('make:page'))
            ->setDescription('Makes a page')
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Module')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Name')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dry')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Write')
            ->addOption('json', null, InputOption::VALUE_NONE, 'As JSON'));

        return new CommandTools($console, $this->runner, [
            new CommandToolSpec('probe_ask', 'probe:ask', 'Ask instead of guessing.'),
            new CommandToolSpec('probe_absent', 'probe:absent', 'Not installed.'),
            new CommandToolSpec('probe_make', 'probe:make', 'A recipe.', keyValueOption: 'arg', reservedKeys: ['force', 'write']),
        ]);
    }
}

final class ProbeRunner implements CommandRunnerInterface
{
    /** @var list<array{string, list<string>, int}> */
    public array $calls = [];

    public CommandRun $next;

    public function __construct()
    {
        $this->next = new CommandRun(0, '', '');
    }

    public function run(string $command, array $arguments, int $timeoutSeconds): CommandRun
    {
        $this->calls[] = [$command, $arguments, $timeoutSeconds];

        return $this->next;
    }
}
