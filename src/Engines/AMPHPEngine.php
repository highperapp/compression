<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Compression\Engines;

use Amp\ByteStream\ReadableResourceStream;
use Amp\Future;
use Amp\Parallel\Worker\Task;
use Amp\Parallel\Worker\WorkerPool;
use HighPerApp\HighPer\Compression\Contracts\CompressionEngineInterface;
use HighPerApp\HighPer\Compression\Exceptions\CompressionException;
use Revolt\EventLoop;

class AMPHPEngine implements CompressionEngineInterface
{
    private ?WorkerPool $workerPool = null;
    private array $performanceMetrics = [];
    private int $chunkSize;
    private bool $isAvailable = false;

    public function __construct(WorkerPool $workerPool = null, int $chunkSize = 8192)
    {
        $this->workerPool = $workerPool;
        $this->chunkSize = $chunkSize;
        $this->checkAvailability();
        $this->initializePerformanceMetrics();
    }

    public function compress(string $data, array $options = []): string
    {
        $algorithm = $options['algorithm'] ?? 'gzip';
        $level = $options['level'] ?? $options['quality'] ?? 6;
        $useParallel = $options['parallel'] ?? (strlen($data) > 10240);

        $startTime = microtime(true);

        try {
            if ($useParallel && strlen($data) > $this->chunkSize * 2) {
                $result = $this->compressParallel($data, $algorithm, $level);
            } else {
                $result = $this->compressSequential($data, $algorithm, $level);
            }

            $this->updatePerformanceMetrics($startTime, strlen($data), strlen($result), $algorithm);
            return $result;
        } catch (\Throwable $e) {
            throw new CompressionException("AMPHP compression failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function decompress(string $data): string
    {
        $startTime = microtime(true);

        try {
            $result = $this->decompressSequential($data);
            $this->updatePerformanceMetrics($startTime, strlen($data), strlen($result), 'decompress');
            return $result;
        } catch (\Throwable $e) {
            throw new CompressionException("AMPHP decompression failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function compressStream($stream, array $options = []): mixed
    {
        if (!is_resource($stream)) {
            throw new CompressionException('Invalid stream resource');
        }

        $algorithm = $options['algorithm'] ?? 'gzip';
        $level = $options['level'] ?? $options['quality'] ?? 6;
        $bufferSize = $options['buffer_size'] ?? $this->chunkSize;

        // Process stream in chunks asynchronously
        return $this->processStreamAsync($stream, $bufferSize, function($chunk) use ($algorithm, $level) {
            return $this->compressSequential($chunk, $algorithm, $level);
        });
    }

    public function decompressStream($stream): mixed
    {
        if (!is_resource($stream)) {
            throw new CompressionException('Invalid stream resource');
        }

        // For decompression, we need the entire compressed data
        $data = stream_get_contents($stream);
        $decompressed = $this->decompress($data);

        // Return as readable stream
        $tempStream = fopen('php://temp', 'r+');
        fwrite($tempStream, $decompressed);
        rewind($tempStream);

        return new ReadableResourceStream($tempStream);
    }

    public function isAvailable(): bool
    {
        return $this->isAvailable;
    }

    public function getName(): string
    {
        return 'amphp';
    }

    public function getSupportedAlgorithms(): array
    {
        $algorithms = ['gzip', 'deflate'];
        
        if (extension_loaded('brotli')) {
            $algorithms[] = 'brotli';
        }
        
        if (extension_loaded('lz4')) {
            $algorithms[] = 'lz4';
        }
        
        $algorithms[] = 'none';
        
        return $algorithms;
    }

    public function getPerformanceMetrics(): array
    {
        return $this->performanceMetrics;
    }

    public function estimateCompressionRatio(int $dataSize, string $algorithm = 'gzip'): float
    {
        // Slightly better estimates for AMPHP due to parallel processing
        return match ($algorithm) {
            'gzip' => 0.55,     // ~45% compression
            'deflate' => 0.6,   // ~40% compression  
            'brotli' => 0.45,   // ~55% compression (if available)
            'lz4' => 0.7,       // ~30% compression (faster, less compression)
            'none' => 1.0,      // No compression
            default => 0.65,    // Conservative default
        };
    }

    public function getBenchmarkScore(): float
    {
        // Benchmark with parallel processing
        $testData = str_repeat('Hello, World! This is a test string for compression benchmarking. ', 200);
        $iterations = 25;
        
        $startTime = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $this->compressParallel($testData, 'gzip', 6);
        }
        
        $totalTime = microtime(true) - $startTime;
        return $totalTime / $iterations;
    }

    private function checkAvailability(): void
    {
        // Check if required extensions and classes are available
        $this->isAvailable = class_exists(WorkerPool::class) && extension_loaded('pcntl');
        
        // Try to create a worker pool if not provided and available
        if ($this->isAvailable && $this->workerPool === null) {
            try {
                // Try different WorkerPool implementations
                if (class_exists('Amp\Parallel\Worker\ContextWorkerPool')) {
                    $this->workerPool = new \Amp\Parallel\Worker\ContextWorkerPool();
                } elseif (class_exists('Amp\Parallel\Worker\ThreadWorkerPool')) {
                    $this->workerPool = new \Amp\Parallel\Worker\ThreadWorkerPool();
                } else {
                    $this->isAvailable = false;
                }
            } catch (\Throwable $e) {
                $this->isAvailable = false;
            }
        }
    }

    private function compressSequential(string $data, string $algorithm, int $level): string
    {
        return match ($algorithm) {
            'gzip' => gzencode($data, $level),
            'deflate' => gzdeflate($data, $level),
            'brotli' => $this->compressBrotli($data, $level),
            'lz4' => $this->compressLz4($data, $level),
            'none' => $data, // No compression
            default => throw new CompressionException("Unsupported algorithm: {$algorithm}")
        };
    }

    private function compressParallel(string $data, string $algorithm, int $level): string
    {
        $chunks = str_split($data, $this->chunkSize);
        $numChunks = count($chunks);

        if ($numChunks <= 1) {
            return $this->compressSequential($data, $algorithm, $level);
        }

        // Create compression tasks
        $futures = [];
        foreach ($chunks as $i => $chunk) {
            $task = new CompressionTask($chunk, $algorithm, $level, $i);
            $futures[] = $this->workerPool->submit($task);
        }

        // Wait for all compressions to complete
        $results = [];
        foreach ($futures as $i => $future) {
            $results[$i] = $future->await();
        }

        // Combine results in order
        ksort($results);
        return implode('', $results);
    }

    private function decompressSequential(string $data): string
    {
        // Auto-detect compression format
        if (strlen($data) >= 2) {
            $header = substr($data, 0, 2);
            
            // Gzip magic number
            if ($header === "\x1f\x8b") {
                $result = gzdecode($data);
                if ($result !== false) {
                    return $result;
                }
            }
        }

        // Try different decompression methods
        $methods = [
            fn() => gzinflate($data),
            fn() => extension_loaded('brotli') ? brotli_uncompress($data) : false,
            fn() => extension_loaded('lz4') ? lz4_uncompress($data) : false,
            fn() => gzdecode($data),
        ];

        foreach ($methods as $method) {
            $result = $method();
            if ($result !== false) {
                return $result;
            }
        }

        throw new CompressionException('Unable to decompress data - unknown format');
    }

    private function compressBrotli(string $data, int $level): string
    {
        if (extension_loaded('brotli')) {
            $result = brotli_compress($data, $level);
            if ($result === false) {
                throw new CompressionException('Brotli compression failed');
            }
            return $result;
        }

        // Fallback to gzip
        return gzencode($data, $level);
    }

    private function compressLz4(string $data, int $level): string
    {
        if (extension_loaded('lz4')) {
            // LZ4 compression level is different (0-16), map from standard 1-9
            $lz4Level = min(16, max(0, (int)($level * 16 / 9)));
            $result = lz4_compress($data, $lz4Level);
            if ($result === false) {
                throw new CompressionException('LZ4 compression failed');
            }
            return $result;
        }

        // Fallback to gzip
        return gzencode($data, $level);
    }

    private function processStreamAsync($stream, int $bufferSize, callable $processor): ReadableResourceStream
    {
        $tempStream = fopen('php://temp', 'r+');
        
        EventLoop::defer(function() use ($stream, $bufferSize, $processor, $tempStream) {
            while (!feof($stream)) {
                $chunk = fread($stream, $bufferSize);
                if ($chunk !== false && $chunk !== '') {
                    $processed = $processor($chunk);
                    fwrite($tempStream, $processed);
                }
            }
            rewind($tempStream);
        });

        return new ReadableResourceStream($tempStream);
    }

    private function initializePerformanceMetrics(): void
    {
        $this->performanceMetrics = [
            'total_compressions' => 0,
            'total_decompressions' => 0,
            'total_bytes_compressed' => 0,
            'total_bytes_decompressed' => 0,
            'total_compression_time' => 0.0,
            'total_decompression_time' => 0.0,
            'average_compression_ratio' => 0.0,
            'average_compression_speed' => 0.0,
            'parallel_tasks_executed' => 0,
            'engine_version' => 'AMPHP',
            'worker_pool_size' => $this->workerPool ? $this->workerPool->getMaxSize() : 0,
        ];
    }

    private function updatePerformanceMetrics(float $startTime, int $inputSize, int $outputSize, string $operation): void
    {
        $duration = microtime(true) - $startTime;

        if ($operation === 'decompress') {
            $this->performanceMetrics['total_decompressions']++;
            $this->performanceMetrics['total_bytes_decompressed'] += $outputSize;
            $this->performanceMetrics['total_decompression_time'] += $duration;
        } else {
            $this->performanceMetrics['total_compressions']++;
            $this->performanceMetrics['total_bytes_compressed'] += $inputSize;
            $this->performanceMetrics['total_compression_time'] += $duration;

            // Update compression ratio
            $ratio = $outputSize / $inputSize;
            $totalCompressions = $this->performanceMetrics['total_compressions'];
            $currentAverage = $this->performanceMetrics['average_compression_ratio'];
            $this->performanceMetrics['average_compression_ratio'] = 
                (($currentAverage * ($totalCompressions - 1)) + $ratio) / $totalCompressions;

            // Update compression speed (bytes per second)
            $speed = $inputSize / $duration;
            $currentSpeedAverage = $this->performanceMetrics['average_compression_speed'];
            $this->performanceMetrics['average_compression_speed'] = 
                (($currentSpeedAverage * ($totalCompressions - 1)) + $speed) / $totalCompressions;
        }
    }
}

class CompressionTask implements Task
{
    private string $data;
    private string $algorithm;
    private int $level;
    private int $chunkIndex;

    public function __construct(string $data, string $algorithm, int $level, int $chunkIndex)
    {
        $this->data = $data;
        $this->algorithm = $algorithm;
        $this->level = $level;
        $this->chunkIndex = $chunkIndex;
    }

    public function run(\Amp\Sync\Channel $channel, \Amp\Cancellation $cancellation): mixed
    {
        return match ($this->algorithm) {
            'gzip' => gzencode($this->data, $this->level),
            'deflate' => gzdeflate($this->data, $this->level),
            'brotli' => extension_loaded('brotli') 
                ? brotli_compress($this->data, $this->level)
                : gzencode($this->data, $this->level),
            'lz4' => extension_loaded('lz4')
                ? lz4_compress($this->data, min(16, max(0, (int)($this->level * 16 / 9))))
                : gzencode($this->data, $this->level),
            'none' => $this->data,
            default => throw new CompressionException("Unsupported algorithm: {$this->algorithm}")
        };
    }
}