# HighPerApp Compression Library

A high-performance compression library for the HighPerApp ecosystem with triple-engine architecture: Rust FFI → AMPHP → Pure PHP fallback.

## Features

- **Interface-driven architecture** - No abstract classes or final keywords
- **Hybrid engine architecture** - Rust FFI + UV → AMPHP + UV → Pure PHP fallback
- **UV extension detection** - Automatic detection and performance optimization
- **Comprehensive compression middleware** - PSR-15 compatible HTTP middleware
- **Environment-based configuration** - 30+ configuration options via environment variables
- **Multiple compression algorithms** - Brotli, Gzip, Deflate, LZ4, and None support
- **PSR compliance** - PSR-4, PSR-3, PSR-11, PSR-15 compatible
- **Auto-discovery support** - Framework integration ready
- **Comprehensive test coverage** - Unit, integration, and performance tests

## Quick Start

### Installation

```bash
composer require highperapp/compression
```

### Build Rust Library (Optional)

```bash
cd rust && ./build.sh
```

### Basic Usage

```php
use HighPerApp\HighPer\Compression\CompressionServiceProvider;

// Bootstrap the compression manager
$provider = new CompressionServiceProvider();
$manager = $provider->bootstrap();

// Simple compression
$data = 'Hello, World! This is test data for compression.';
$compressed = $manager->compress($data, 'brotli');

if (strlen($compressed) < strlen($data)) {
    echo "Compression successful!";
    
    // Decompress
    $decompressed = $manager->decompress($compressed);
    echo "Original data: " . $decompressed;
}

// Auto-algorithm selection with content-type aware optimization
$options = ['content_type' => 'application/json'];
$compressed = $manager->compress($data, 'auto', $options);
$decompressed = $manager->decompress($compressed, 'auto');

// Force no compression for pre-compressed data
$compressed = $manager->compress($data, 'none');
echo $compressed === $data ? 'No compression applied' : 'Compressed';
```

### Middleware Usage

```php
use HighPerApp\HighPer\Compression\CompressionServiceProvider;

// Get middleware instance
$provider = new CompressionServiceProvider();
$middleware = $provider->getMiddleware();

// Use in PSR-15 compatible middleware stack
$middlewareStack = [
    $middleware,
    // ... other middleware
];

// The middleware will automatically compress responses based on:
// - Client Accept-Encoding headers
// - Content-Type (compressible types only)
// - Response size (minimum threshold)
```

### Async Compression

```php
// Large dataset compression with automatic parallelization
$largeData = file_get_contents('large-file.txt');
$compressedFuture = $manager->compressAsync($largeData, 'brotli');
$compressed = $compressedFuture->await();
```

### Stream Compression

```php
// Compress streams for large files with stateful compression
$inputStream = fopen('large-file.txt', 'r');
$compressedStream = $manager->compressStream($inputStream, 'gzip', [
    'buffer_size' => 8192,  // Chunk size for processing
    'level' => 6           // Compression level
]);

// Process compressed stream
while (!feof($compressedStream->getResource())) {
    $chunk = fread($compressedStream->getResource(), 8192);
    // Process chunk...
}

// Stateful streaming compression maintains compression context
// across chunks for better compression ratios
```

## Engine Configuration

### Environment Variables

```bash
# Engine preferences
COMPRESSION_PREFERRED_ENGINE=rust_ffi
COMPRESSION_RUST_FFI_ENABLED=true
COMPRESSION_AMPHP_ENABLED=true
COMPRESSION_PURE_PHP_ENABLED=true

# Performance tuning
COMPRESSION_ASYNC_THRESHOLD=1024
COMPRESSION_PARALLEL_WORKERS=4

# Compression settings
COMPRESSION_BROTLI_QUALITY=6
COMPRESSION_GZIP_LEVEL=6
COMPRESSION_LZ4_LEVEL=8

# Auto-selection thresholds (bytes)
COMPRESSION_TINY_THRESHOLD=512
COMPRESSION_SMALL_THRESHOLD=2048
COMPRESSION_MEDIUM_THRESHOLD=32768
COMPRESSION_LARGE_THRESHOLD=1048576

# Auto-selection algorithms
COMPRESSION_TINY_ALGORITHM=none
COMPRESSION_SMALL_ALGORITHM=lz4
COMPRESSION_MEDIUM_ALGORITHM=gzip
COMPRESSION_LARGE_ALGORITHM=brotli
COMPRESSION_XLARGE_ALGORITHM=gzip

# Content-type awareness
COMPRESSION_USE_CONTENT_TYPE=true
COMPRESSION_SKIP_RATIO_THRESHOLD=0.8

# Debug settings
COMPRESSION_DEBUG=false
COMPRESSION_LOG_LEVEL=info

# Security settings
COMPRESSION_MAX_INPUT_SIZE=52428800
COMPRESSION_TIMEOUT=30
```

### Manual Configuration

```php
$config = [
    'engines' => [
        'rust_ffi' => [
            'enabled' => true,
            'priority' => 1,
            'algorithms' => ['brotli', 'gzip'],
        ],
        'amphp' => [
            'enabled' => true,
            'priority' => 2,
            'algorithms' => ['gzip', 'deflate', 'lz4'],
        ],
        'pure_php' => [
            'enabled' => true,
            'priority' => 3,
            'algorithms' => ['gzip', 'deflate', 'brotli', 'lz4', 'none'],
        ],
    ],
    'preferred_engine' => 'rust_ffi',
    'performance' => [
        'async_threshold' => 1024,
        'parallel_workers' => 4,
        'benchmark_on_startup' => true,
    ],
    'compression' => [
        'brotli' => [
            'quality' => 6,
            'window_size' => 22,
        ],
        'gzip' => [
            'level' => 6,
        ],
        'lz4' => [
            'level' => 8,  // 0-16 range, higher = better compression
        ],
    ],
    'auto_selection' => [
        'thresholds' => [
            'tiny' => 512,      // Use 'none' for data this small
            'small' => 2048,    // Use 'lz4' for speed
            'medium' => 32768,  // Use 'gzip' for balance
            'large' => 1048576, // Use 'brotli' for best compression
        ],
        'algorithms' => [
            'tiny' => 'none',
            'small' => 'lz4',
            'medium' => 'gzip',
            'large' => 'brotli',
            'xlarge' => 'gzip', // Streaming-optimized
        ],
        'use_content_type' => true,
        'content_type_map' => [
            'text/' => 'brotli',
            'application/json' => 'brotli',
            'application/xml' => 'brotli',
            'image/' => 'none',
            'video/' => 'none',
            'application/octet-stream' => 'lz4',
        ],
    ],
];

$provider = new CompressionServiceProvider(null, $config);
```

## Available Compression Algorithms

### Brotli (Best Compression)
- **Best compression ratio** - Up to 20% better than gzip
- **Modern algorithm** - Optimized for web content
- **Variable quality** - Levels 0-11 available
- **Browser support** - All modern browsers
- **Use cases** - Text files, JSON, HTML, CSS, JavaScript

### LZ4 (Fastest)
- **Ultra-fast compression** - Optimized for speed over ratio
- **Low CPU usage** - Minimal processing overhead
- **Variable levels** - Levels 0-16 available
- **Real-time friendly** - Sub-millisecond compression
- **Use cases** - Real-time data, logging, temporary storage

### Gzip (Balanced)
- **Universal compatibility** - Supported everywhere
- **Good performance** - Balanced speed and compression
- **Variable levels** - Levels 1-9 available
- **HTTP standard** - Built into HTTP protocol
- **Use cases** - General web content, API responses

### Deflate (Minimal Overhead)
- **Raw compression** - No headers/checksums
- **Minimal overhead** - Smallest possible output
- **Fast processing** - Optimized for speed
- **Legacy support** - Works with older systems
- **Use cases** - Embedded systems, protocols

### None (Pass-through)
- **No compression** - Data returned as-is
- **Zero overhead** - No processing time
- **Auto-selected** - For already compressed data
- **Explicit option** - When compression is not desired
- **Use cases** - Images, videos, pre-compressed files

## Engine Information

### Rust FFI Engine (Performance Level 1)
- **Fastest** - Native Rust implementation with C FFI
- **Parallel processing** - Automatic batch optimization
- **Advanced algorithms** - Optimized Brotli implementation
- **Requirements** - FFI extension, compiled Rust library
- **Performance** - ~50,000+ operations/sec

### AMPHP Engine (Performance Level 2)
- **amphp/parallel** - AMPHP-based parallel processing
- **Worker pools** - Configurable worker management
- **Good performance** - Balanced speed and compatibility
- **Requirements** - AMPHP packages, pcntl extension
- **Performance** - ~15,000+ operations/sec

### Pure PHP Engine (Performance Level 3)
- **Universal compatibility** - Works everywhere
- **No dependencies** - Only standard PHP functions
- **Reliable fallback** - Always available
- **Full featured** - All compression algorithms supported
- **Performance** - ~5,000+ operations/sec

## Framework Integration

### HighPer Framework (Native Integration)

```php
// The compression library is automatically integrated into HighPer Framework
// through the CompressionServiceProvider. No manual setup required.

// Access compression manager in HighPer applications
$container = $app->getContainer();
$compressionManager = $container->get(CompressionManagerInterface::class);
$compressionMiddleware = $container->get(CompressionMiddleware::class);

// Available aliases:
// - 'compression' → CompressionManagerInterface
// - 'compression.manager' → CompressionManagerInterface  
// - 'compression.middleware' → CompressionMiddleware
// - 'compression.config' → ConfigurationInterface
```

### Laravel

```php
// In a service provider
public function register()
{
    $this->app->singleton(CompressionManagerInterface::class, function ($app) {
        $provider = new CompressionServiceProvider();
        return $provider->bootstrap();
    });
}

// Use middleware in HTTP kernel
protected $middleware = [
    // ...
    \HighPerApp\HighPer\Compression\Middleware\CompressionMiddleware::class,
];
```

### Symfony

```yaml
# services.yaml
services:
    HighPerApp\HighPer\Compression\Contracts\CompressionManagerInterface:
        factory: ['@HighPerApp\HighPer\Compression\CompressionServiceProvider', 'bootstrap']
    
    HighPerApp\HighPer\Compression\CompressionServiceProvider: ~
```

### PSR-11 Container

```php
$container->set(CompressionManagerInterface::class, function() {
    $provider = new CompressionServiceProvider();
    return $provider->bootstrap();
});
```

## Performance Benchmarks

| Engine | Operations/sec | Use Case | Memory Usage |
|--------|----------------|----------|--------------|
| Rust FFI | ~50,000+ | High-throughput APIs | Low |
| AMPHP | ~15,000+ | Moderate async workloads | Medium |
| Pure PHP | ~5,000+ | Standard applications | Low |

### Compression Ratios

| Algorithm | Text Files | JSON | HTML | Binary | Speed |
|-----------|------------|------|------|--------|-------|
| Brotli | ~70% | ~80% | ~75% | ~60% | Slow |
| Gzip | ~60% | ~70% | ~65% | ~50% | Medium |
| Deflate | ~55% | ~65% | ~60% | ~45% | Fast |
| LZ4 | ~25% | ~35% | ~30% | ~20% | Ultra Fast |
| None | 0% | 0% | 0% | 0% | Instant |

## Build Requirements

### Rust Library
- Rust 1.70+
- Cargo
- FFI-enabled PHP 8.3+

### PHP Requirements
- PHP 8.3+
- Composer
- Optional: AMPHP packages for parallel engine
- Optional: UV extension for enhanced performance

## Development

### Building from Source

```bash
# Full build with tests
./rust/build.sh --test

# Debug build with verbose output
./rust/build.sh -t debug -v

# Clean rebuild
./rust/build.sh -c -f

# PHP setup only (skip Rust)
composer install
```

### Running Tests

```bash
# All tests
composer test

# Unit tests only
vendor/bin/phpunit tests/Unit

# Integration tests
vendor/bin/phpunit tests/Integration

# Performance tests
vendor/bin/phpunit tests/Performance

# With coverage
composer test-coverage
```

### Code Quality

```bash
# Static analysis
composer phpstan

# Code style check
composer cs-check

# Fix code style
composer cs-fix
```

## Security

This library follows security best practices:
- Input size limits to prevent DoS attacks
- Compression ratio limits to detect compression bombs
- Timeout protection for long-running operations
- Memory usage monitoring
- Secure default configurations
- No code execution in compression algorithms

## Monitoring and Metrics

```php
// Get compression statistics
$stats = $manager->getCompressionStats();
echo "Total operations: " . $stats['total_operations'];
echo "Average compression ratio: " . $stats['average_compression_ratio'];

// Benchmark engines
$benchmarks = $manager->benchmarkEngines();
foreach ($benchmarks as $engine => $metrics) {
    echo "{$engine}: {$metrics['score']} ms average";
}

// Performance metrics per engine
foreach ($manager->getAvailableEngines() as $engine) {
    $metrics = $engine->getPerformanceMetrics();
    echo $engine->getName() . " metrics: " . json_encode($metrics);
    echo "Supported algorithms: " . implode(', ', $engine->getSupportedAlgorithms());
}

// Test optimal algorithm selection
$testData = 'Sample data for testing';
$algorithm = $manager->getOptimalAlgorithm(strlen($testData), 'application/json');
echo "Optimal algorithm for JSON data: " . $algorithm;
```

## License

MIT License - see LICENSE file

## Contributing

1. Fork the repository
2. Create feature branch
3. Add tests for new functionality
4. Ensure all tests pass
5. Submit pull request

## Support

- GitHub Issues: Report bugs and feature requests