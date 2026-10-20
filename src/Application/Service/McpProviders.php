<?php

declare(strict_types=1);

namespace Semitexa\Mcp\Application\Service;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Mcp\Attribute\AsMcpProvider;
use Semitexa\Mcp\Domain\Contract\McpPromptProviderInterface;
use Semitexa\Mcp\Domain\Contract\McpResourceProviderInterface;
use Semitexa\Mcp\Domain\Contract\McpToolProviderInterface;

/**
 * The providers the installed packages declare with #[AsMcpProvider], built
 * through the container, in class-name order. One that cannot be built is
 * said and left out: a broken package must not take the others' tools.
 */
#[AsService]
final class McpProviders
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    #[InjectAsReadonly]
    protected ContainerInterface $container;

    /**
     * @param \Closure(string): void $report
     * @return list<object>
     */
    public function all(\Closure $report): array
    {
        $this->classDiscovery->initialize();
        $classes = $this->classDiscovery->findClassesWithAttribute(AsMcpProvider::class);
        sort($classes);

        $providers = [];
        foreach ($classes as $class) {
            try {
                $provider = $this->container->get($class);
                if (!$provider instanceof McpToolProviderInterface
                    && !$provider instanceof McpResourceProviderInterface
                    && !$provider instanceof McpPromptProviderInterface
                ) {
                    throw new \LogicException('it implements none of the MCP provider interfaces');
                }
                $providers[] = $provider;
            } catch (\Throwable $e) {
                $report(sprintf('MCP provider %s is left out: %s', $class, $e->getMessage()));
            }
        }

        return $providers;
    }
}
