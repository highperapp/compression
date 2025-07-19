<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Compression\Contracts;

interface ConfigurationInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function has(string $key): bool;

    public function all(): array;

    public function merge(array $config): void;

    public function getEngineConfig(string $engineName): array;

    public function getCompressionConfig(string $algorithm): array;

    public function isEngineEnabled(string $engineName): bool;

    public function getPreferredEngine(): string;

    public function getAsyncThreshold(): int;

    public function getParallelWorkers(): int;

    public function getCompressionQuality(string $algorithm): int;

    public function isDebugEnabled(): bool;
}