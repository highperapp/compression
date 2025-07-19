<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Compression\FFI;

use FFI;
use HighPerApp\HighPer\Compression\Exceptions\CompressionException;

class RustCompressionFFI
{
    private ?FFI $ffi = null;
    private bool $isAvailable = false;
    private string $libraryPath;

    public function __construct(string $libraryPath = null)
    {
        $this->libraryPath = $libraryPath ?? $this->findLibraryPath();
        $this->initialize();
    }

    public function isAvailable(): bool
    {
        return $this->isAvailable;
    }

    public function compress(string $data, int $quality = 6, int $windowSize = 22): string
    {
        if (!$this->isAvailable) {
            throw new CompressionException('Rust FFI compression engine is not available');
        }

        $dataPtr = $this->ffi->new('char[' . strlen($data) . ']');
        FFI::memcpy($dataPtr, $data, strlen($data));

        $outputLen = $this->ffi->new('size_t');
        $outputPtr = $this->ffi->compress(
            FFI::cast('uint8_t*', $dataPtr),
            strlen($data),
            $quality,
            $windowSize,
            FFI::addr($outputLen)
        );

        if (FFI::isNull($outputPtr)) {
            throw new CompressionException('Compression failed');
        }

        $result = FFI::string($outputPtr, $outputLen->cdata);
        $this->ffi->free_memory($outputPtr);

        return $result;
    }

    public function decompress(string $data): string
    {
        if (!$this->isAvailable) {
            throw new CompressionException('Rust FFI compression engine is not available');
        }

        $dataPtr = $this->ffi->new('char[' . strlen($data) . ']');
        FFI::memcpy($dataPtr, $data, strlen($data));

        $outputLen = $this->ffi->new('size_t');
        $outputPtr = $this->ffi->decompress(
            FFI::cast('uint8_t*', $dataPtr),
            strlen($data),
            FFI::addr($outputLen)
        );

        if (FFI::isNull($outputPtr)) {
            throw new CompressionException('Decompression failed');
        }

        $result = FFI::string($outputPtr, $outputLen->cdata);
        $this->ffi->free_memory($outputPtr);

        return $result;
    }

    public function compressString(string $data, int $quality = 6, int $windowSize = 22): string
    {
        if (!$this->isAvailable) {
            throw new CompressionException('Rust FFI compression engine is not available');
        }

        $outputLen = $this->ffi->new('size_t');
        $outputPtr = $this->ffi->compress_string(
            $data,
            $quality,
            $windowSize,
            FFI::addr($outputLen)
        );

        if (FFI::isNull($outputPtr)) {
            throw new CompressionException('String compression failed');
        }

        $result = FFI::string($outputPtr, $outputLen->cdata);
        $this->ffi->free_memory($outputPtr);

        return $result;
    }

    public function decompressToString(string $data): string
    {
        if (!$this->isAvailable) {
            throw new CompressionException('Rust FFI compression engine is not available');
        }

        $dataPtr = $this->ffi->new('char[' . strlen($data) . ']');
        FFI::memcpy($dataPtr, $data, strlen($data));

        $outputPtr = $this->ffi->decompress_to_string(
            FFI::cast('uint8_t*', $dataPtr),
            strlen($data)
        );

        if (FFI::isNull($outputPtr)) {
            throw new CompressionException('String decompression failed');
        }

        $result = FFI::string($outputPtr);
        $this->ffi->free_string($outputPtr);

        return $result;
    }

    public function getRecommendedQuality(int $inputSize): int
    {
        if (!$this->isAvailable) {
            return 6; // Default fallback
        }

        return $this->ffi->get_recommended_quality($inputSize);
    }

    public function estimateCompressedSize(int $inputSize, int $quality): int
    {
        if (!$this->isAvailable) {
            return (int) ($inputSize * 0.5); // Default estimation
        }

        return $this->ffi->estimate_compressed_size($inputSize, $quality);
    }

    public function getVersion(): string
    {
        if (!$this->isAvailable) {
            return 'unavailable';
        }

        return FFI::string($this->ffi->get_version());
    }

    public function benchmarkCompression(string $data, int $iterations = 100): float
    {
        if (!$this->isAvailable) {
            return -1.0;
        }

        $dataPtr = $this->ffi->new('char[' . strlen($data) . ']');
        FFI::memcpy($dataPtr, $data, strlen($data));

        return $this->ffi->benchmark_compression(
            FFI::cast('uint8_t*', $dataPtr),
            strlen($data),
            $iterations
        );
    }

    private function initialize(): void
    {
        if (!extension_loaded('ffi')) {
            return;
        }

        if (!file_exists($this->libraryPath)) {
            return;
        }

        try {
            $this->ffi = FFI::cdef(
                $this->getCDefinitions(),
                $this->libraryPath
            );
            $this->isAvailable = true;
        } catch (\Throwable $e) {
            $this->isAvailable = false;
        }
    }

    private function findLibraryPath(): string
    {
        $possiblePaths = [
            __DIR__ . '/../../rust/target/release/libbrotli_compressor.so',
            __DIR__ . '/../../rust/target/release/libbrotli_compressor.dylib',
            __DIR__ . '/../../rust/target/release/brotli_compressor.dll',
            '/usr/local/lib/libbrotli_compressor.so',
            '/usr/lib/libbrotli_compressor.so',
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return '';
    }

    private function getCDefinitions(): string
    {
        return '
            uint8_t* compress(const uint8_t* input_data, size_t input_len, uint32_t quality, uint32_t window_size, size_t* output_len);
            uint8_t* decompress(const uint8_t* input_data, size_t input_len, size_t* output_len);
            uint8_t* compress_string(const char* input, uint32_t quality, uint32_t window_size, size_t* output_len);
            char* decompress_to_string(const uint8_t* input_data, size_t input_len);
            uint32_t get_recommended_quality(size_t input_size);
            size_t estimate_compressed_size(size_t input_size, uint32_t quality);
            void free_memory(uint8_t* ptr);
            void free_string(char* s);
            const char* get_version();
            double benchmark_compression(const uint8_t* input_data, size_t input_len, uint32_t iterations);
        ';
    }
}