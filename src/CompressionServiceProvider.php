<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Compression;

use Amp\Parallel\Worker\WorkerPool;
use HighPerApp\HighPer\Compression\Configuration\Configuration;
use HighPerApp\HighPer\Compression\Contracts\CompressionManagerInterface;
use HighPerApp\HighPer\Compression\Contracts\ConfigurationInterface;
use HighPerApp\HighPer\Compression\Middleware\CompressionMiddleware;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

class CompressionServiceProvider
{
    private ContainerInterface $container;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private ?CompressionManagerInterface $manager = null;
    private ?CompressionMiddleware $middleware = null;

    public function __construct(
        ContainerInterface $container = null,
        array $config = [],
        LoggerInterface $logger = null
    ) {
        $this->container = $container ?? new SimpleContainer();
        $this->config = new Configuration($config);
        $this->logger = $logger ?? new NullLogger();
        
        $this->registerServices();
        $this->initializeEventLoop();
    }

    public function bootstrap(): CompressionManagerInterface
    {
        if ($this->manager === null) {
            $this->manager = $this->createCompressionManager();
            
            if ($this->config->isDebugEnabled()) {
                $this->logger->info('Compression manager bootstrapped', [
                    'engines' => array_map(fn($e) => $e->getName(), $this->manager->getAvailableEngines()),
                    'preferred_engine' => $this->manager->getPreferredEngine(),
                ]);
            }
        }
        
        return $this->manager;
    }

    public function getMiddleware(): CompressionMiddleware
    {
        if ($this->middleware === null) {
            $this->middleware = new CompressionMiddleware(
                $this->bootstrap(),
                $this->config,
                $this->logger
            );
        }
        
        return $this->middleware;
    }

    public function getConfiguration(): ConfigurationInterface
    {
        return $this->config;
    }

    public function getManager(): CompressionManagerInterface
    {
        return $this->bootstrap();
    }

    public function registerFrameworkServices(ContainerInterface $container): void
    {
        // Register compression manager
        if (method_exists($container, 'set')) {
            $container->set(CompressionManagerInterface::class, fn() => $this->bootstrap());
            $container->set(ConfigurationInterface::class, fn() => $this->config);
            $container->set(CompressionMiddleware::class, fn() => $this->getMiddleware());
        }
    }

    public function warmUp(): void
    {
        // Pre-initialize components for better performance
        $manager = $this->bootstrap();
        
        if ($this->config->get('performance.benchmark_on_startup', true)) {
            $benchmarks = $manager->benchmarkEngines();
            
            $this->logger->info('Compression engines benchmarked', [
                'results' => array_map(fn($b) => [
                    'score' => $b['score'],
                    'available' => $b['available'],
                ], $benchmarks),
            ]);
        }
    }

    private function registerServices(): void
    {
        // Register configuration
        if (method_exists($this->container, 'set')) {
            $this->container->set(ConfigurationInterface::class, $this->config);
            $this->container->set(LoggerInterface::class, $this->logger);
        }
    }

    private function initializeEventLoop(): void
    {
        // Initialize revolt/event-loop with UV if available
        if (extension_loaded('uv')) {
            $this->logger->info('UV extension detected, optimizing event loop');
        }
        
        // Setup periodic compression stats collection if debug is enabled
        if ($this->config->isDebugEnabled()) {
            $this->setupPeriodicStatsCollection();
        }
    }

    private function createCompressionManager(): CompressionManagerInterface
    {
        return new CompressionManager($this->config, $this->logger);
    }

    private function setupPeriodicStatsCollection(): void
    {
        EventLoop::repeat(60.0, function() {
            if ($this->manager) {
                $stats = $this->manager->getCompressionStats();
                
                $this->logger->info('Compression statistics', [
                    'total_operations' => $stats['total_operations'],
                    'average_compression_ratio' => $stats['average_compression_ratio'],
                    'operations_by_engine' => $stats['operations_by_engine'] ?? [],
                ]);
            }
        });
    }
}

class SimpleContainer implements ContainerInterface
{
    private array $services = [];
    private array $instances = [];

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->services[$id])) {
            $factory = $this->services[$id];
            $instance = is_callable($factory) ? $factory() : $factory;
            $this->instances[$id] = $instance;
            return $instance;
        }

        throw new \InvalidArgumentException("Service '{$id}' not found");
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]) || isset($this->instances[$id]);
    }

    public function set(string $id, mixed $service): void
    {
        $this->services[$id] = $service;
        unset($this->instances[$id]); // Clear cached instance
    }
}