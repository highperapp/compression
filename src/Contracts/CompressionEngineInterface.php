<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Compression\Contracts;

interface CompressionEngineInterface
{
    public function compress(string $data, array $options = []): string;

    public function decompress(string $data): string;

    public function compressStream($stream, array $options = []): mixed;

    public function decompressStream($stream): mixed;

    public function isAvailable(): bool;

    public function getName(): string;

    public function getSupportedAlgorithms(): array;

    public function getPerformanceMetrics(): array;

    public function estimateCompressionRatio(int $dataSize, string $algorithm = 'brotli'): float;

    public function getBenchmarkScore(): float;
}