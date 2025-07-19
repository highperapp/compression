<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Compression\Contracts;

interface CompressionManagerInterface
{
    public function compress(string $data, string $algorithm = 'auto', array $options = []): string;

    public function decompress(string $data, string $algorithm = 'auto'): string;

    public function compressAsync(string $data, string $algorithm = 'auto', array $options = []): mixed;

    public function decompressAsync(string $data, string $algorithm = 'auto'): mixed;

    public function compressStream($stream, string $algorithm = 'auto', array $options = []): mixed;

    public function decompressStream($stream, string $algorithm = 'auto'): mixed;

    public function setPreferredEngine(string $engineName): void;

    public function getPreferredEngine(): string;

    public function getAvailableEngines(): array;

    public function getOptimalAlgorithm(int $dataSize, string $contentType = ''): string;

    public function benchmarkEngines(): array;

    public function getCompressionStats(): array;
}