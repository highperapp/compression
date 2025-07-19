<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Compression;

use Amp\Future;
use HighPerApp\HighPer\Compression\Contracts\CompressionEngineInterface;
use HighPerApp\HighPer\Compression\Contracts\CompressionManagerInterface;
use HighPerApp\HighPer\Compression\Contracts\ConfigurationInterface;
use HighPerApp\HighPer\Compression\Engines\AMPHPEngine;
use HighPerApp\HighPer\Compression\Engines\PurePHPEngine;
use HighPerApp\HighPer\Compression\Engines\RustFFIEngine;
use HighPerApp\HighPer\Compression\Exceptions\CompressionException;
use HighPerApp\HighPer\Compression\Exceptions\EngineNotAvailableException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

class CompressionManager implements CompressionManagerInterface
{
    private array $engines = [];
    private array $enginePriorities = [];
    private string $preferredEngine = 'auto';
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private array $benchmarkCache = [];
    private array $compressionStats = [];

    public function __construct(
        ConfigurationInterface $config,
        LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
        $this->initializeEngines();
        $this->initializeStats();
    }

    public function compress(string $data, string $algorithm = 'auto', array $options = []): string
    {
        $contentType = $options['content_type'] ?? '';
        $originalAlgorithm = $algorithm;
        if ($algorithm === 'auto') {
            $algorithm = $this->getOptimalAlgorithm(strlen($data), $contentType);
            $options['auto_selected'] = true;
        }
        
        $engine = $this->selectEngine($algorithm, strlen($data));
        $options['algorithm'] = $algorithm;
        
        // Skip compression for very small gains only when using auto selection
        if ($algorithm !== 'none' && $originalAlgorithm === 'auto' && $this->shouldSkipCompression($data, $algorithm, $engine)) {
            $this->logger->debug('Skipping compression due to estimated poor ratio', [
                'data_size' => strlen($data),
                'algorithm' => $algorithm,
                'content_type' => $contentType
            ]);
            $algorithm = 'none';
            $options['algorithm'] = $algorithm;
        }
        
        try {
            $startTime = microtime(true);
            $result = $engine->compress($data, $options);
            $this->updateCompressionStats($startTime, strlen($data), strlen($result), $algorithm, $engine->getName());
            
            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Compression failed', [
                'engine' => $engine->getName(),
                'algorithm' => $algorithm,
                'data_size' => strlen($data),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
    
    private function shouldSkipCompression(string $data, string $algorithm, CompressionEngineInterface $engine): bool
    {
        $dataSize = strlen($data);
        
        // Always compress if data is large enough
        if ($dataSize > 4096) {
            return false;
        }
        
        // Skip compression for very small data unless specifically requested
        if ($dataSize < 256) {
            return true;
        }
        
        // Estimate compression ratio
        $estimatedRatio = $engine->estimateCompressionRatio($dataSize, $algorithm);
        $estimatedSavings = $dataSize * (1 - $estimatedRatio);
        
        // Skip if estimated savings are less than 100 bytes or 20%
        return $estimatedSavings < 100 || $estimatedRatio > 0.8;
    }

    public function decompress(string $data, string $algorithm = 'auto'): string
    {
        $engine = $this->selectEngine($algorithm, strlen($data));
        
        try {
            $startTime = microtime(true);
            $result = $engine->decompress($data);
            $this->updateCompressionStats($startTime, strlen($data), strlen($result), 'decompress', $engine->getName());
            
            return $result;
        } catch (\Throwable $e) {
            // Try other engines as fallback
            foreach ($this->getAvailableEngines() as $fallbackEngine) {
                if ($fallbackEngine->getName() === $engine->getName()) {
                    continue;
                }
                
                try {
                    $result = $fallbackEngine->decompress($data);
                    $this->logger->info('Decompression succeeded with fallback engine', [
                        'primary_engine' => $engine->getName(),
                        'fallback_engine' => $fallbackEngine->getName(),
                    ]);
                    return $result;
                } catch (\Throwable $fallbackError) {
                    continue;
                }
            }
            
            $this->logger->error('All decompression attempts failed', [
                'data_size' => strlen($data),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function compressAsync(string $data, string $algorithm = 'auto', array $options = []): Future
    {
        return \Amp\async(function() use ($data, $algorithm, $options) {
            if (strlen($data) < $this->config->getAsyncThreshold()) {
                return $this->compress($data, $algorithm, $options);
            }

            $engine = $this->selectEngine($algorithm, strlen($data));
            $algorithm = $algorithm === 'auto' ? $this->getOptimalAlgorithm(strlen($data)) : $algorithm;
            
            $options['algorithm'] = $algorithm;
            $options['parallel'] = true;

            return $engine->compress($data, $options);
        });
    }

    public function decompressAsync(string $data, string $algorithm = 'auto'): Future
    {
        return \Amp\async(function() use ($data, $algorithm) {
            if (strlen($data) < $this->config->getAsyncThreshold()) {
                return $this->decompress($data, $algorithm);
            }

            $engine = $this->selectEngine($algorithm, strlen($data));
            return $engine->decompress($data);
        });
    }

    public function compressStream($stream, string $algorithm = 'auto', array $options = []): mixed
    {
        $engine = $this->selectEngine($algorithm);
        $algorithm = $algorithm === 'auto' ? 'gzip' : $algorithm; // Default for streams
        
        $options['algorithm'] = $algorithm;
        
        return $engine->compressStream($stream, $options);
    }

    public function decompressStream($stream, string $algorithm = 'auto'): mixed
    {
        $engine = $this->selectEngine($algorithm);
        return $engine->decompressStream($stream);
    }

    public function setPreferredEngine(string $engineName): void
    {
        if (!isset($this->engines[$engineName]) && $engineName !== 'auto') {
            throw new EngineNotAvailableException("Engine '{$engineName}' is not available");
        }
        
        $this->preferredEngine = $engineName;
        $this->logger->info('Preferred compression engine changed', ['engine' => $engineName]);
    }

    public function getPreferredEngine(): string
    {
        return $this->preferredEngine;
    }

    public function getAvailableEngines(): array
    {
        return array_values($this->engines);
    }

    public function getOptimalAlgorithm(int $dataSize, string $contentType = ''): string
    {
        // Use configuration-based auto-selection
        $thresholds = $this->config->get('auto_selection.thresholds', []);
        $algorithms = $this->config->get('auto_selection.algorithms', []);
        $useContentType = $this->config->get('auto_selection.use_content_type', true);
        
        // Content-type based selection
        if ($useContentType && $contentType) {
            $algorithm = $this->getAlgorithmForContentType($contentType, $dataSize);
            if ($algorithm) {
                return $algorithm;
            }
        }
        
        // Size-based selection with enhanced logic
        if ($dataSize <= ($thresholds['tiny'] ?? 512)) {
            // For very small data, use no compression or fast compression
            return $algorithms['tiny'] ?? 'none';
        } elseif ($dataSize <= ($thresholds['small'] ?? 2048)) {
            // For small data, prefer speed over compression ratio
            return $algorithms['small'] ?? 'lz4';
        } elseif ($dataSize <= ($thresholds['medium'] ?? 32768)) {
            // For medium data, balance speed and compression
            return $algorithms['medium'] ?? 'gzip';
        } elseif ($dataSize <= ($thresholds['large'] ?? 1048576)) {
            // For large data, prefer better compression
            return $algorithms['large'] ?? 'brotli';
        } else {
            // For very large data, use streaming-optimized compression
            return $algorithms['xlarge'] ?? 'gzip';
        }
    }
    
    private function getAlgorithmForContentType(string $contentType, int $dataSize): ?string
    {
        $contentTypeMap = $this->config->get('auto_selection.content_type_map', [
            'text/' => 'brotli',        // Text content compresses well with brotli
            'application/json' => 'brotli',
            'application/xml' => 'brotli',
            'application/javascript' => 'brotli',
            'text/css' => 'brotli',
            'text/html' => 'brotli',
            'image/' => 'none',         // Images are usually already compressed
            'video/' => 'none',         // Videos are usually already compressed
            'audio/' => 'none',         // Audio is usually already compressed
            'application/octet-stream' => 'lz4', // Binary data, prefer speed
            'application/pdf' => 'lz4', // PDFs may have some redundancy
            'application/zip' => 'none', // Already compressed
            'application/gzip' => 'none', // Already compressed
        ]);
        
        // Find best match
        foreach ($contentTypeMap as $pattern => $algorithm) {
            if (str_starts_with($contentType, $pattern)) {
                // For very small files, consider using no compression
                if ($dataSize < 1024 && in_array($algorithm, ['brotli', 'gzip'])) {
                    return 'none';
                }
                return $algorithm;
            }
        }
        
        return null;
    }

    public function benchmarkEngines(): array
    {
        if (!empty($this->benchmarkCache) && $this->config->get('performance.cache_benchmarks', true)) {
            return $this->benchmarkCache;
        }

        $results = [];
        foreach ($this->engines as $name => $engine) {
            $this->logger->info("Benchmarking engine: {$name}");
            $score = $engine->getBenchmarkScore();
            $results[$name] = [
                'score' => $score,
                'available' => $engine->isAvailable(),
                'algorithms' => $engine->getSupportedAlgorithms(),
                'metrics' => $engine->getPerformanceMetrics(),
            ];
        }

        // Sort by performance (lower score is better)
        uasort($results, fn($a, $b) => $a['score'] <=> $b['score']);

        $this->benchmarkCache = $results;
        return $results;
    }

    public function getCompressionStats(): array
    {
        return $this->compressionStats;
    }

    private function initializeEngines(): void
    {
        // Initialize engines based on configuration
        $engineConfigs = $this->config->get('engines', []);
        
        foreach ($engineConfigs as $name => $config) {
            if (!($config['enabled'] ?? false)) {
                continue;
            }

            $engine = $this->createEngine($name);
            if ($engine && $engine->isAvailable()) {
                $this->engines[$name] = $engine;
                $this->enginePriorities[$name] = $config['priority'] ?? 999;
                
                $this->logger->info("Engine '{$name}' initialized successfully");
            } else {
                $this->logger->warning("Engine '{$name}' is not available");
            }
        }

        // Sort engines by priority
        asort($this->enginePriorities);

        if (empty($this->engines)) {
            throw new EngineNotAvailableException('No compression engines are available');
        }

        $this->preferredEngine = $this->config->getPreferredEngine();
    }

    private function createEngine(string $name): ?CompressionEngineInterface
    {
        return match ($name) {
            'rust_ffi' => new RustFFIEngine(),
            'amphp' => new AMPHPEngine(),
            'pure_php' => new PurePHPEngine(),
            default => null,
        };
    }

    private function selectEngine(string $algorithm = 'auto', int $dataSize = 0): CompressionEngineInterface
    {
        if ($this->preferredEngine !== 'auto' && isset($this->engines[$this->preferredEngine])) {
            return $this->engines[$this->preferredEngine];
        }

        // Auto-select based on performance benchmarks
        if ($this->config->get('performance.benchmark_on_startup', true)) {
            $benchmarks = $this->benchmarkEngines();
            
            foreach ($benchmarks as $name => $benchmark) {
                if (isset($this->engines[$name]) && $benchmark['available']) {
                    return $this->engines[$name];
                }
            }
        }

        // Fallback to priority-based selection
        foreach ($this->enginePriorities as $name => $priority) {
            if (isset($this->engines[$name])) {
                return $this->engines[$name];
            }
        }

        throw new EngineNotAvailableException('No suitable compression engine found');
    }

    private function initializeStats(): void
    {
        $this->compressionStats = [
            'total_operations' => 0,
            'total_compression_time' => 0.0,
            'total_bytes_processed' => 0,
            'total_bytes_output' => 0,
            'operations_by_engine' => [],
            'operations_by_algorithm' => [],
            'average_compression_ratio' => 0.0,
        ];
    }

    private function updateCompressionStats(float $startTime, int $inputSize, int $outputSize, string $algorithm, string $engine): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->compressionStats['total_operations']++;
        $this->compressionStats['total_compression_time'] += $duration;
        $this->compressionStats['total_bytes_processed'] += $inputSize;
        $this->compressionStats['total_bytes_output'] += $outputSize;
        
        // Track by engine
        if (!isset($this->compressionStats['operations_by_engine'][$engine])) {
            $this->compressionStats['operations_by_engine'][$engine] = 0;
        }
        $this->compressionStats['operations_by_engine'][$engine]++;
        
        // Track by algorithm
        if (!isset($this->compressionStats['operations_by_algorithm'][$algorithm])) {
            $this->compressionStats['operations_by_algorithm'][$algorithm] = 0;
        }
        $this->compressionStats['operations_by_algorithm'][$algorithm]++;
        
        // Update average compression ratio
        if ($algorithm !== 'decompress' && $inputSize > 0) {
            $ratio = $outputSize / $inputSize;
            $totalOps = $this->compressionStats['total_operations'];
            $currentAverage = $this->compressionStats['average_compression_ratio'];
            $this->compressionStats['average_compression_ratio'] = 
                (($currentAverage * ($totalOps - 1)) + $ratio) / $totalOps;
        }
    }
}