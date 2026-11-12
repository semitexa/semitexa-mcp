<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Application\Service;

use Semitexa\Mcp\Domain\Contract\CommandRunnerInterface;
use Semitexa\Mcp\Domain\Contract\McpToolProviderInterface;
use Semitexa\Mcp\Domain\Model\CommandRun;
use Semitexa\Mcp\Domain\Model\CommandToolSpec;
use Semitexa\Mcp\Domain\Model\McpTool;
use Semitexa\Mcp\Domain\Model\McpToolResult;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/**
 * Tools that run bin/semitexa commands (tk-mcp-curated-tools). Each input
 * schema is built from the command's own definition, so a tool cannot offer
 * an option the command lost or miss one it gained; a command that is not
 * installed gives no tool. What an agent may not set (output format, trace
 * ids, overwriting) is kept out of the schema, and an input outside it is
 * refused rather than passed on.
 *
 * Every make:* generator is one tool, semitexa_generate, so the list stays
 * short: the generator is an input, and its options are checked against that
 * generator's definition.
 */
final class CommandTools implements McpToolProviderInterface
{
    public const GENERATE = 'semitexa_generate';

    /** Output control and bookkeeping: the tool decides these, not the agent. */
    private const NEVER_OFFERED = ['json', 'ndjson', 'human', 'trace'];

    /** What a generator would overwrite, or a duplicate it would add: not an agent's call. */
    private const GENERATOR_HIDDEN = ['force', 'override-duplicate', 'dry-run', 'write', 'llm-hints'];

    /** Past this, a result is cut: an agent's context is the budget. */
    public const MAX_TEXT = 24000;

    /** @var array<string, array{spec: CommandToolSpec, command: Command}>|null */
    private ?array $byTool = null;

    /** @param list<CommandToolSpec> $specs */
    public function __construct(
        private readonly Application $console,
        private readonly CommandRunnerInterface $runner,
        private readonly array $specs,
    ) {
    }

    public function tools(): array
    {
        $tools = [];
        foreach ($this->byTool() as $name => ['spec' => $spec, 'command' => $command]) {
            $tools[] = new McpTool(
                $name,
                trim($command->getDescription() . "\n\n" . $spec->guidance),
                $this->schema($command->getNativeDefinition(), $this->notOffered($spec)),
                readOnly: $spec->readOnly,
                destructive: $spec->destructive,
                idempotent: $spec->idempotent,
            );
        }
        $generators = $this->generators();
        if ($generators !== []) {
            $tools[] = $this->generateTool($generators);
        }

        return $tools;
    }

    public function call(string $name, array $arguments): McpToolResult
    {
        if ($name === self::GENERATE) {
            return $this->generate($arguments);
        }
        $entry = $this->byTool()[$name] ?? null;
        if ($entry === null) {
            return McpToolResult::error("No tool \"{$name}\".");
        }
        $spec = $entry['spec'];
        $refused = self::reservedKeySet($spec, $arguments);
        if ($refused !== null) {
            return McpToolResult::error($refused);
        }
        $argv = $this->argv($entry['command']->getNativeDefinition(), $this->notOffered($spec), $arguments, $spec->fixed);
        if (is_string($argv)) {
            return McpToolResult::error($argv);
        }

        return $this->reachable(self::answer($spec->command, $this->runner->run($spec->command, $argv, $spec->timeoutSeconds)));
    }

    /** @param array<string, mixed> $arguments */
    private function generate(array $arguments): McpToolResult
    {
        $unknown = array_diff(array_keys($arguments), ['generator', 'options', 'write']);
        if ($unknown !== []) {
            return McpToolResult::error(sprintf('"%s" is not an input of this tool: a generator\'s options go in options.', implode('", "', $unknown)));
        }
        if (array_key_exists('write', $arguments) && !is_bool($arguments['write'])) {
            return McpToolResult::error('write is true or false.');
        }
        $generator = is_string($arguments['generator'] ?? null) ? $arguments['generator'] : '';
        $command = $this->generators()[$generator] ?? null;
        if ($command === null) {
            return McpToolResult::error("No generator \"{$generator}\". One of: " . implode(', ', array_keys($this->generators())) . '.');
        }
        $options = $arguments['options'] ?? [];
        if (!is_array($options) || ($options !== [] && array_is_list($options))) {
            return McpToolResult::error('options is an object of the generator\'s options.');
        }
        /** @var array<string, mixed> $options */
        $fixed = [($arguments['write'] ?? false) === true ? '--write' : '--dry-run', '--json'];
        $argv = $this->argv($command->getNativeDefinition(), array_merge(self::NEVER_OFFERED, self::GENERATOR_HIDDEN), $options, $fixed);
        if (is_string($argv)) {
            return McpToolResult::error($argv);
        }

        return $this->reachable(self::answer($generator, $this->runner->run($generator, $argv, 120)));
    }

    /** @param array<string, Command> $generators */
    private function generateTool(array $generators): McpTool
    {
        $lines = [];
        foreach ($generators as $name => $command) {
            $options = array_keys($this->schema($command->getNativeDefinition(), array_merge(self::NEVER_OFFERED, self::GENERATOR_HIDDEN))['properties']);
            $requires = self::requiredOptions($command);
            $lines[] = sprintf(
                '- %s: %s%s Options: %s.',
                $name,
                rtrim($command->getDescription(), '.') . '.',
                $requires !== [] ? ' Requires: ' . implode(', ', $requires) . '.' : '',
                implode(', ', $options),
            );
        }

        return new McpTool(
            self::GENERATE,
            "Generate code the framework expects with one of its generators. Without write it only shows what it would create; "
                . "call it again with write: true to create the files. It never overwrites. semitexa_task suggests which generator and options fit a task.\n\n"
                . implode("\n", $lines),
            [
                'type' => 'object',
                'properties' => [
                    'generator' => ['type' => 'string', 'enum' => array_keys($generators), 'description' => 'Which generator'],
                    'options' => ['type' => 'object', 'description' => 'Its options, by name without the leading dashes, e.g. {"module": "Blog", "name": "ShowPost"}', 'additionalProperties' => true],
                    'write' => ['type' => 'boolean', 'description' => 'Create the files (default: show what would be created)', 'default' => false],
                ],
                'required' => ['generator'],
            ],
            title: 'Generate code',
        );
    }

    /**
     * The options a generator refuses to run without, from its REQUIRED_OPTIONS
     * (every make:* declares one; Symfony's VALUE_REQUIRED only means "takes a
     * value"). An agent used to learn them from a refusal, one call each
     * (tk-mcp-generate-names-required-options).
     *
     * @return list<string>
     */
    private static function requiredOptions(Command $command): array
    {
        if ($command instanceof LazyCommand) {
            $command = $command->getCommand();
        }
        $constant = $command::class . '::REQUIRED_OPTIONS';
        $required = defined($constant) ? constant($constant) : [];

        return is_array($required) ? array_values(array_filter($required, 'is_string')) : [];
    }

    /**
     * @param list<string> $notOffered
     * @return array{type: 'object', properties: array<string, array<string, mixed>>, required?: list<string>}
     */
    private function schema(InputDefinition $definition, array $notOffered): array
    {
        $properties = [];
        $required = [];
        foreach ($definition->getArguments() as $argument) {
            $properties[$argument->getName()] = self::argumentSchema($argument);
            if ($argument->isRequired()) {
                $required[] = $argument->getName();
            }
        }
        foreach ($definition->getOptions() as $option) {
            if (!in_array($option->getName(), $notOffered, true)) {
                $properties[$option->getName()] = self::optionSchema($option);
            }
        }
        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private static function argumentSchema(InputArgument $argument): array
    {
        $schema = $argument->isArray()
            ? ['type' => 'array', 'items' => ['type' => 'string']]
            : ['type' => 'string'];
        $schema['description'] = $argument->getDescription();
        $default = $argument->getDefault();
        if (is_scalar($default) && $default !== '') {
            $schema['default'] = $default;
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private static function optionSchema(InputOption $option): array
    {
        if (!$option->acceptValue()) {
            return ['type' => 'boolean', 'description' => $option->getDescription()];
        }
        $schema = $option->isArray()
            ? ['type' => 'array', 'items' => ['type' => ['string', 'number']]]
            : ['type' => ['string', 'number']];
        $schema['description'] = $option->getDescription();
        $default = $option->getDefault();
        if (is_scalar($default) && $default !== '' && $default !== false) {
            $schema['default'] = $default;
        }

        return $schema;
    }

    /**
     * The command line for what the agent sent, or why it cannot be built:
     * options, then the fixed arguments, then "--" and the positional values,
     * so a value that starts with a dash is a value, never an option.
     *
     * @param list<string>         $notOffered
     * @param array<string, mixed> $input
     * @param list<string>         $fixed
     * @return list<string>|string
     */
    private function argv(InputDefinition $definition, array $notOffered, array $input, array $fixed): array|string
    {
        $positional = [];
        foreach ($definition->getArguments() as $argument) {
            $value = $input[$argument->getName()] ?? null;
            unset($input[$argument->getName()]);
            if ($value === null) {
                continue;
            }
            foreach ($argument->isArray() ? (array) $value : [$value] as $item) {
                if (!is_scalar($item)) {
                    return sprintf('"%s" is text.', $argument->getName());
                }
                $positional[] = self::scalar($item);
            }
        }
        $argv = [];
        foreach ($input as $name => $value) {
            if (!$definition->hasOption($name) || in_array($name, $notOffered, true)) {
                return sprintf('"%s" is not an input of this tool.', $name);
            }
            $option = $definition->getOption($name);
            if (!$option->acceptValue()) {
                if (!is_bool($value)) {
                    return sprintf('"%s" is true or false.', $name);
                }
                if ($value) {
                    $argv[] = "--{$name}";
                }
                continue;
            }
            foreach ($option->isArray() ? (array) $value : [$value] as $item) {
                if ($item === null) {
                    continue;
                }
                if (!is_scalar($item)) {
                    return sprintf('"%s" is text%s.', $name, $option->isArray() ? ', or a list of text' : '');
                }
                $argv[] = "--{$name}=" . self::scalar($item);
            }
        }
        $argv = array_merge($argv, $fixed);
        if ($positional !== []) {
            $argv[] = '--';
            array_push($argv, ...$positional);
        }

        return $argv;
    }

    /**
     * A key=value option whose key would set what the tool keeps from the
     * agent: make copies an --arg into every step, so arg "force=1" was --force.
     *
     * @param array<string, mixed> $arguments
     */
    private static function reservedKeySet(CommandToolSpec $spec, array $arguments): ?string
    {
        if ($spec->keyValueOption === null || !isset($arguments[$spec->keyValueOption])) {
            return null;
        }
        foreach ((array) $arguments[$spec->keyValueOption] as $pair) {
            $key = is_scalar($pair) ? strtolower(trim(explode('=', (string) $pair, 2)[0])) : '';
            if (in_array($key, $spec->reservedKeys, true)) {
                return sprintf('%s cannot set "%s": this tool decides it.', $spec->keyValueOption, $key);
            }
        }

        return null;
    }

    private static function scalar(bool|int|float|string $value): string
    {
        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }

    /**
     * The next commands an answer names, as the tools that run them
     * (tk-mcp-next-command-unreachable). The commands write next_command as
     * console calls ({cmd, args, why}); an agent here has only these tools, so
     * each entry becomes {tool, arguments, why}, and an entry no tool can run
     * is dropped instead of sending the agent to a shell it may not have.
     */
    private function reachable(McpToolResult $result): McpToolResult
    {
        if ($result->isError || !is_array($result->structured)) {
            return $result;
        }

        return McpToolResult::json($this->rewriteNext($result->structured));
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function rewriteNext(array $value): array
    {
        foreach ($value as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            if ($key === 'next_command' && array_is_list($item)) {
                $value[$key] = $this->asTools($item);
                continue;
            }
            $value[$key] = $this->rewriteNext($item);
        }

        return $value;
    }

    /**
     * @param list<mixed> $entries
     * @return list<array{tool: string, arguments: array<string, mixed>, why?: string}>
     */
    private function asTools(array $entries): array
    {
        $tools = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['cmd'] ?? null)) {
                continue;
            }
            $args = array_values(array_filter(is_array($entry['args'] ?? null) ? $entry['args'] : [], 'is_string'));
            $call = $this->toolFor($entry['cmd'], $args);
            if ($call === null) {
                continue;
            }
            if (is_string($entry['why'] ?? null)) {
                $call['why'] = $entry['why'];
            }
            $tools[] = $call;
        }

        return $tools;
    }

    /**
     * @param list<string> $args
     * @return array{tool: string, arguments: array<string, mixed>}|null null when no tool runs it as written
     */
    private function toolFor(string $command, array $args): ?array
    {
        if (isset($this->generators()[$command])) {
            // write and dry-run are the tool's own input (write: true), not an option.
            $options = $this->toolArguments($this->generators()[$command]->getNativeDefinition(), array_merge(self::NEVER_OFFERED, self::GENERATOR_HIDDEN), $args, ['--write', '--dry-run']);
            if ($options === null) {
                return null;
            }
            $arguments = ['generator' => $command, 'options' => (object) $options];
            if (in_array('--write', $args, true)) {
                $arguments['write'] = true;
            }

            return ['tool' => self::GENERATE, 'arguments' => $arguments];
        }
        foreach ($this->byTool() as $tool => ['spec' => $spec, 'command' => $console]) {
            if ($spec->command !== $command) {
                continue;
            }
            $arguments = $this->toolArguments($console->getNativeDefinition(), $this->notOffered($spec), $args, $spec->fixed);
            // What call() would refuse is not a next step (review, mcp#6).
            if ($arguments === null || self::reservedKeySet($spec, $arguments) !== null) {
                return null;
            }

            return ['tool' => $tool, 'arguments' => $arguments];
        }

        return null;
    }

    /**
     * The tool's input for a console call: positional values by argument name,
     * --name=value and --name as options. Options the tool sets itself (fixed,
     * --json) are left out; one it does not offer means it cannot run this.
     *
     * @param list<string> $notOffered
     * @param list<string> $args
     * @param list<string> $fixed
     * @return array<string, mixed>|null
     */
    private function toolArguments(InputDefinition $definition, array $notOffered, array $args, array $fixed = []): ?array
    {
        /** @var array<string, mixed> $input */
        $input = [];
        $positional = [];
        foreach ($args as $arg) {
            if (in_array($arg, $fixed, true) || $arg === '--') {
                continue;
            }
            if (!str_starts_with($arg, '--')) {
                $positional[] = $arg;
                continue;
            }
            $body = substr($arg, 2);
            $eq = strpos($body, '=');
            $name = $eq === false ? $body : substr($body, 0, $eq);
            $value = $eq === false ? true : substr($body, $eq + 1);
            if (in_array($name, self::NEVER_OFFERED, true)) {
                continue;
            }
            if (!$definition->hasOption($name) || in_array($name, $notOffered, true)) {
                return null;
            }
            $option = $definition->getOption($name);
            // The form must fit the option, as argv() reads it back: a flag
            // written --name, a value written --name=value (review, mcp#6).
            if ($option->acceptValue() !== is_string($value)) {
                return null;
            }
            if ($option->isArray()) {
                $input[$name] = [...(array) ($input[$name] ?? []), $value];
            } else {
                $input[$name] = $value;
            }
        }
        foreach ($definition->getArguments() as $argument) {
            if ($positional === []) {
                break;
            }
            $input[$argument->getName()] = $argument->isArray() ? $positional : array_shift($positional);
            if ($argument->isArray()) {
                $positional = [];
            }
        }

        return $positional === [] ? $input : null;
    }

    /**
     * The command's output as the agent reads it: its JSON (one document, or
     * one per line), else its text. A command that failed is a failure the
     * agent sees, with what it said.
     */
    private static function answer(string $command, CommandRun $run): McpToolResult
    {
        $output = self::decode($run->stdout);
        $encoded = is_string($output) ? $output : (string) json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // Too long for the agent's context: the text, cut, instead of the value.
        $tooLong = strlen($encoded) > self::MAX_TEXT;
        if ($run->succeeded()) {
            if (is_string($output) || $tooLong) {
                return McpToolResult::text(self::cut($encoded));
            }

            return McpToolResult::json($output);
        }
        $why = $run->timedOut ? "{$command} did not finish in time." : "{$command} exited with {$run->exitCode}.";
        $detail = ['output' => is_string($output) || $tooLong ? self::cut($encoded) : $output];
        $stderr = trim($run->stderr);
        if ($stderr !== '') {
            $detail['stderr'] = self::cut($stderr, 4000);
        }

        return McpToolResult::error($why, $detail);
    }

    private static function decode(string $stdout): mixed
    {
        $text = trim($stdout);
        if ($text === '') {
            return '';
        }
        $whole = json_decode($text, true);
        if ($whole !== null || $text === 'null') {
            return $whole;
        }
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if ($decoded === null) {
                return $text;
            }
            $lines[] = $decoded;
        }

        return $lines;
    }

    private static function cut(string $text, int $max = self::MAX_TEXT): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max) . sprintf("\n… cut: %d more characters. Narrow the request to see them.", strlen($text) - $max);
    }

    /** @return list<string> */
    private function notOffered(CommandToolSpec $spec): array
    {
        $fixed = array_map(static fn (string $a): string => (string) preg_replace('/^--([^=]+).*$/', '$1', $a), $spec->fixed);

        return array_values(array_unique(array_merge(self::NEVER_OFFERED, $spec->hidden, $fixed)));
    }

    /** @return array<string, array{spec: CommandToolSpec, command: Command}> */
    private function byTool(): array
    {
        if ($this->byTool !== null) {
            return $this->byTool;
        }
        $byTool = [];
        foreach ($this->specs as $spec) {
            if ($this->console->has($spec->command)) {
                $byTool[$spec->tool] = ['spec' => $spec, 'command' => $this->console->get($spec->command)];
            }
        }

        return $this->byTool = $byTool;
    }

    /** @return array<string, Command> name => generator, sorted */
    private function generators(): array
    {
        $generators = [];
        foreach ($this->console->all('make') as $name => $command) {
            if ($command->getName() === $name && str_starts_with($name, 'make:')) {
                $generators[$name] = $command;
            }
        }
        ksort($generators);

        return $generators;
    }
}
