<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Compression\Configuration;

use HighPerApp\HighPer\Compression\Contracts\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    private array $config;

    private array $defaultConfig = [
        'engines' => [
            'rust_ffi' => [
                'enabled' => true,
                'priority' => 1,
                'algorithms' => ['brotli', 'gzip'],
                'auto_detect' => true,
            ],
            'pure_php' => [
                'enabled' => true,
                'priority' => 3,
                'algorithms' => ['gzip', 'deflate'],
                'auto_detect' => true,
            ],
            'amphp' => [
                'enabled' => true,
                'priority' => 2,
                'algorithms' => ['gzip'],
                'auto_detect' => true,
            ],
        ],
        'preferred_engine' => 'auto',
        'performance' => [
            'async_threshold' => 1024,
            'parallel_workers' => 4,
            'benchmark_on_startup' => true,
            'cache_benchmarks' => true,
        ],
        'compression' => [
            'brotli' => [
                'quality' => 6,
                'window_size' => 22,
                'mode' => 'generic',
            ],
            'gzip' => [
                'level' => 6,
                'encoding' => 'gzip',
                'strategy' => 'default',
            ],
            'deflate' => [
                'level' => 6,
                'strategy' => 'default',
            ],
        ],
        'auto_selection' => [
            'enable' => true,
            'thresholds' => [
                'small' => 1024,
                'medium' => 10240,
                'large' => 102400,
            ],
            'algorithms' => [
                'small' => 'gzip',
                'medium' => 'brotli',
                'large' => 'brotli',
            ],
        ],
        'security' => [
            'max_compression_ratio' => 1000,
            'max_input_size' => 50 * 1024 * 1024, // 50MB
            'timeout' => 30,
        ],
        'debug' => [
            'enabled' => false,
            'log_level' => 'info',
            'track_performance' => true,
        ],
    ];

    public function __construct(array $config = [])
    {
        $this->config = $this->mergeConfigs($this->defaultConfig, $config);
        $this->loadEnvironmentConfig();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->getNestedValue($this->config, $key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->setNestedValue($this->config, $key, $value);
    }

    public function has(string $key): bool
    {
        return $this->getNestedValue($this->config, $key) !== null;
    }

    public function all(): array
    {
        return $this->config;
    }

    public function merge(array $config): void
    {
        $this->config = $this->mergeConfigs($this->config, $config);
    }

    public function getEngineConfig(string $engineName): array
    {
        return $this->get("engines.{$engineName}", []);
    }

    public function getCompressionConfig(string $algorithm): array
    {
        return $this->get("compression.{$algorithm}", []);
    }

    public function isEngineEnabled(string $engineName): bool
    {
        return (bool) $this->get("engines.{$engineName}.enabled", false);
    }

    public function getPreferredEngine(): string
    {
        return $this->get('preferred_engine', 'auto');
    }

    public function getAsyncThreshold(): int
    {
        return (int) $this->get('performance.async_threshold');
    }

    public function getParallelWorkers(): int
    {
        return (int) $this->get('performance.parallel_workers');
    }

    public function getCompressionQuality(string $algorithm): int
    {
        return match ($algorithm) {
            'brotli' => (int) $this->get('compression.brotli.quality'),
            'gzip' => (int) $this->get('compression.gzip.level'),
            'deflate' => (int) $this->get('compression.deflate.level'),
            default => 6,
        };
    }

    public function isDebugEnabled(): bool
    {
        return (bool) $this->get('debug.enabled');
    }

    private function loadEnvironmentConfig(): void
    {
        $envMapping = [
            'COMPRESSION_PREFERRED_ENGINE' => 'preferred_engine',
            'COMPRESSION_RUST_FFI_ENABLED' => 'engines.rust_ffi.enabled',
            'COMPRESSION_PURE_PHP_ENABLED' => 'engines.pure_php.enabled',
            'COMPRESSION_AMPHP_ENABLED' => 'engines.amphp.enabled',
            'COMPRESSION_ASYNC_THRESHOLD' => 'performance.async_threshold',
            'COMPRESSION_PARALLEL_WORKERS' => 'performance.parallel_workers',
            'COMPRESSION_BROTLI_QUALITY' => 'compression.brotli.quality',
            'COMPRESSION_GZIP_LEVEL' => 'compression.gzip.level',
            'COMPRESSION_DEBUG' => 'debug.enabled',
            'COMPRESSION_LOG_LEVEL' => 'debug.log_level',
            'COMPRESSION_MAX_INPUT_SIZE' => 'security.max_input_size',
            'COMPRESSION_TIMEOUT' => 'security.timeout',
        ];

        foreach ($envMapping as $envKey => $configKey) {
            $value = $_ENV[$envKey] ?? getenv($envKey);
            if ($value !== false) {
                $this->set($configKey, $this->castValue($value));
            }
        }
    }

    private function castValue(string $value): mixed
    {
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }
        return $value;
    }

    private function getNestedValue(array $array, string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $array;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    private function setNestedValue(array &$array, string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $k) {
            if (!isset($current[$k]) || !is_array($current[$k])) {
                $current[$k] = [];
            }
            $current = &$current[$k];
        }

        $current = $value;
    }

    private function mergeConfigs(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeConfigs($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}