<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Compression\Engines;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableResourceStream;
use HighPerApp\HighPer\Compression\Contracts\CompressionEngineInterface;
use HighPerApp\HighPer\Compression\Exceptions\CompressionException;
use HighPerApp\HighPer\Compression\FFI\RustCompressionFFI;

class RustFFIEngine implements CompressionEngineInterface
{
    private RustCompressionFFI $ffi;
    private array $performanceMetrics = [];

    public function __construct()
    {
        $this->ffi = new RustCompressionFFI();
        $this->initializePerformanceMetrics();
    }

    public function compress(string $data, array $options = []): string
    {
        if (!$this->isAvailable()) {
            throw new CompressionException('Rust FFI engine is not available');
        }

        $algorithm = $options['algorithm'] ?? 'brotli';
        $quality = $options['quality'] ?? $this->ffi->getRecommendedQuality(strlen($data));
        $windowSize = $options['window_size'] ?? 22;

        $startTime = microtime(true);

        try {
            switch ($algorithm) {
                case 'brotli':
                    $result = $this->ffi->compressString($data, $quality, $windowSize);
                    break;
                case 'gzip':
                    // For gzip, we'll use a gzip wrapper around the brotli compression
                    $compressed = $this->ffi->compressString($data, $quality, $windowSize);
                    $result = gzencode($compressed, $quality);
                    break;
                case 'lz4':
                    $result = $this->ffi->compressLz4($data, $quality);
                    break;
                case 'none':
                    $result = $data; // No compression
                    break;
                default:
                    throw new CompressionException("Unsupported algorithm: {$algorithm}");
            }

            $this->updatePerformanceMetrics($startTime, strlen($data), strlen($result), $algorithm);
            return $result;
        } catch (\Throwable $e) {
            throw new CompressionException("Compression failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function decompress(string $data): string
    {
        if (!$this->isAvailable()) {
            throw new CompressionException('Rust FFI engine is not available');
        }

        $startTime = microtime(true);

        try {
            // Auto-detect compression format
            if ($this->isBrotliData($data)) {
                $result = $this->ffi->decompressToString($data);
            } elseif ($this->isGzipData($data)) {
                $result = gzdecode($data);
            } elseif ($this->isLz4Data($data)) {
                $result = $this->ffi->decompressLz4($data);
            } else {
                // Try Brotli decompression as fallback
                $result = $this->ffi->decompressToString($data);
            }

            $this->updatePerformanceMetrics($startTime, strlen($data), strlen($result), 'decompress');
            return $result;
        } catch (\Throwable $e) {
            throw new CompressionException("Decompression failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function compressStream($stream, array $options = []): mixed
    {
        if (!is_resource($stream)) {
            throw new CompressionException('Invalid stream resource');
        }

        $algorithm = $options['algorithm'] ?? 'brotli';
        $bufferSize = $options['buffer_size'] ?? 8192;

        // Read stream in chunks and compress
        $compressed = '';
        while (!feof($stream)) {
            $chunk = fread($stream, $bufferSize);
            if ($chunk !== false && $chunk !== '') {
                $compressed .= $this->compress($chunk, $options);
            }
        }

        // Return as readable stream
        $tempStream = fopen('php://temp', 'r+');
        fwrite($tempStream, $compressed);
        rewind($tempStream);

        return new ReadableResourceStream($tempStream);
    }

    public function decompressStream($stream): mixed
    {
        if (!is_resource($stream)) {
            throw new CompressionException('Invalid stream resource');
        }

        // Read entire stream and decompress
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
        return $this->ffi->isAvailable();
    }

    public function getName(): string
    {
        return 'rust_ffi';
    }

    public function getSupportedAlgorithms(): array
    {
        $algorithms = ['brotli', 'gzip'];
        
        if ($this->ffi->supportsLz4()) {
            $algorithms[] = 'lz4';
        }
        
        $algorithms[] = 'none';
        
        return $algorithms;
    }

    public function getPerformanceMetrics(): array
    {
        return $this->performanceMetrics;
    }

    public function estimateCompressionRatio(int $dataSize, string $algorithm = 'brotli'): float
    {
        if (!$this->isAvailable()) {
            return match ($algorithm) {
                'brotli' => 0.4,
                'gzip' => 0.6,
                'lz4' => 0.75,
                'none' => 1.0,
                default => 0.5
            };
        }

        switch ($algorithm) {
            case 'brotli':
                $quality = $this->ffi->getRecommendedQuality($dataSize);
                $estimatedSize = $this->ffi->estimateCompressedSize($dataSize, $quality);
                return $estimatedSize / $dataSize;
            case 'lz4':
                return $this->ffi->estimateLz4CompressedSize($dataSize) / $dataSize;
            case 'none':
                return 1.0;
            default:
                $quality = $this->ffi->getRecommendedQuality($dataSize);
                $estimatedSize = $this->ffi->estimateCompressedSize($dataSize, $quality);
                return $estimatedSize / $dataSize;
        }
    }

    public function getBenchmarkScore(): float
    {
        if (!$this->isAvailable()) {
            return 0.0;
        }

        // Use a standard test payload
        $testData = str_repeat('Hello, World! This is a test string for compression benchmarking. ', 100);
        return $this->ffi->benchmarkCompression($testData, 50);
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
            'engine_version' => $this->ffi->getVersion(),
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

    private function isBrotliData(string $data): bool
    {
        // Simple heuristic: Brotli streams typically don't start with gzip magic numbers
        return !$this->isGzipData($data);
    }

    private function isGzipData(string $data): bool
    {
        // Check for gzip magic number
        return strlen($data) >= 2 && substr($data, 0, 2) === "\x1f\x8b";
    }

    private function isLz4Data(string $data): bool
    {
        // Check for LZ4 magic number
        return strlen($data) >= 4 && substr($data, 0, 4) === "\x04\x22\x4d\x18";
    }
}