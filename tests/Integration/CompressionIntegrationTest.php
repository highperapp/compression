<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Compression\Tests\Integration;

use HighPerApp\HighPer\Compression\CompressionServiceProvider;
use HighPerApp\HighPer\Compression\Contracts\CompressionManagerInterface;
use HighPerApp\HighPer\Compression\Middleware\CompressionMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CompressionIntegrationTest extends TestCase
{
    private CompressionServiceProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new CompressionServiceProvider(
            null,
            [
                'engines' => [
                    'pure_php' => [
                        'enabled' => true,
                        'priority' => 1,
                    ],
                    'amphp' => [
                        'enabled' => true,
                        'priority' => 2,
                    ],
                    'rust_ffi' => [
                        'enabled' => false,
                    ],
                ],
                'debug' => ['enabled' => true],
            ]
        );
    }

    public function testServiceProviderBootstrap(): void
    {
        $manager = $this->provider->bootstrap();
        
        $this->assertInstanceOf(CompressionManagerInterface::class, $manager);
        $this->assertNotEmpty($manager->getAvailableEngines());
    }

    public function testEndToEndCompression(): void
    {
        $manager = $this->provider->bootstrap();
        
        $testData = 'This is a comprehensive end-to-end compression test. ' . 
                   str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 50);
        
        // Test compression and decompression
        $compressed = $manager->compress($testData);
        $this->assertNotEmpty($compressed);
        $this->assertLessThan(strlen($testData), strlen($compressed));
        
        $decompressed = $manager->decompress($compressed);
        $this->assertEquals($testData, $decompressed);
    }

    public function testMultipleAlgorithms(): void
    {
        $manager = $this->provider->bootstrap();
        
        $testData = str_repeat('Multi-algorithm compression test. ', 100);
        $algorithms = ['gzip', 'deflate'];
        
        foreach ($algorithms as $algorithm) {
            $compressed = $manager->compress($testData, $algorithm);
            $decompressed = $manager->decompress($compressed);
            
            $this->assertEquals($testData, $decompressed, "Failed for algorithm: {$algorithm}");
            $this->assertLessThan(strlen($testData), strlen($compressed), "No compression for: {$algorithm}");
        }
    }

    public function testMiddlewareIntegration(): void
    {
        $middleware = $this->provider->getMiddleware();
        $this->assertInstanceOf(CompressionMiddleware::class, $middleware);
        
        // Create mock request with gzip support
        $request = $this->createMockRequest([
            'Accept-Encoding' => 'gzip, deflate, br',
        ]);
        
        // Create mock response with compressible content
        $responseContent = str_repeat('This is compressible content. ', 100);
        $response = $this->createMockResponse($responseContent, 'text/html');
        
        // Create mock handler
        $handler = $this->createMockHandler($response);
        
        // Process through middleware
        $processedResponse = $middleware->process($request, $handler);
        
        // Verify compression was applied
        $this->assertTrue($processedResponse->hasHeader('Content-Encoding'));
        $this->assertTrue($processedResponse->hasHeader('X-Compression-Engine'));
        $this->assertLessThan(
            strlen($responseContent),
            (int) $processedResponse->getHeaderLine('Content-Length')
        );
    }

    public function testStreamProcessing(): void
    {
        $manager = $this->provider->bootstrap();
        
        $testData = str_repeat('Stream processing test data. ', 200);
        
        // Create input stream
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, $testData);
        rewind($inputStream);
        
        // Compress stream
        $compressedStream = $manager->compressStream($inputStream, 'gzip');
        $this->assertIsObject($compressedStream);
        
        // Decompress stream
        $decompressedStream = $manager->decompressStream($compressedStream->getResource());
        $decompressedData = stream_get_contents($decompressedStream->getResource());
        
        $this->assertEquals($testData, $decompressedData);
        
        fclose($inputStream);
    }

    public function testPerformanceMetrics(): void
    {
        $manager = $this->provider->bootstrap();
        
        // Perform multiple operations
        $testData = str_repeat('Performance metrics test. ', 50);
        
        for ($i = 0; $i < 5; $i++) {
            $compressed = $manager->compress($testData);
            $manager->decompress($compressed);
        }
        
        $stats = $manager->getCompressionStats();
        
        $this->assertGreaterThanOrEqual(5, $stats['total_operations']);
        $this->assertGreaterThan(0, $stats['total_compression_time']);
        $this->assertGreaterThan(0, $stats['total_bytes_processed']);
    }

    public function testBenchmarking(): void
    {
        $manager = $this->provider->bootstrap();
        
        $benchmarks = $manager->benchmarkEngines();
        $this->assertNotEmpty($benchmarks);
        
        foreach ($benchmarks as $engineName => $benchmark) {
            $this->assertIsString($engineName);
            $this->assertArrayHasKey('score', $benchmark);
            $this->assertArrayHasKey('available', $benchmark);
            $this->assertArrayHasKey('algorithms', $benchmark);
            
            if ($benchmark['available']) {
                $this->assertGreaterThan(0, $benchmark['score']);
                $this->assertIsArray($benchmark['algorithms']);
                $this->assertNotEmpty($benchmark['algorithms']);
            }
        }
    }

    public function testLargeDataHandling(): void
    {
        $manager = $this->provider->bootstrap();
        
        // Test with large data (1MB)
        $largeData = str_repeat('Large data compression test. This is a longer string to create substantial content. ', 10000);
        
        $startTime = microtime(true);
        $compressed = $manager->compress($largeData);
        $compressionTime = microtime(true) - $startTime;
        
        $startTime = microtime(true);
        $decompressed = $manager->decompress($compressed);
        $decompressionTime = microtime(true) - $startTime;
        
        $this->assertEquals($largeData, $decompressed);
        $this->assertLessThan(strlen($largeData), strlen($compressed));
        
        // Performance should be reasonable (adjust thresholds as needed)
        $this->assertLessThan(1.0, $compressionTime, 'Compression took too long');
        $this->assertLessThan(0.5, $decompressionTime, 'Decompression took too long');
    }

    public function testConfigurationIntegration(): void
    {
        $config = $this->provider->getConfiguration();
        
        $this->assertTrue($config->isEngineEnabled('pure_php'));
        $this->assertTrue($config->isDebugEnabled());
        $this->assertIsArray($config->getEngineConfig('pure_php'));
    }

    public function testErrorRecovery(): void
    {
        $manager = $this->provider->bootstrap();
        
        // Test with corrupted data
        try {
            $manager->decompress('corrupted_data_that_should_fail');
            $this->fail('Expected CompressionException was not thrown');
        } catch (\HighPerApp\HighPer\Compression\Exceptions\CompressionException $e) {
            $this->assertStringContainsString('failed', strtolower($e->getMessage()));
        }
    }

    private function createMockRequest(array $headers = []): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        
        $request->method('getHeaderLine')
            ->willReturnCallback(function ($name) use ($headers) {
                return $headers[$name] ?? '';
            });
        
        return $request;
    }

    private function createMockResponse(string $content, string $contentType = 'text/plain'): ResponseInterface
    {
        $response = new class($content, $contentType) implements ResponseInterface {
            private array $headers = [];
            private string $content;
            private string $contentType;
            
            public function __construct(string $content, string $contentType) {
                $this->content = $content;
                $this->contentType = $contentType;
                $this->headers['Content-Type'] = [$contentType];
            }
            
            public function getProtocolVersion(): string { return '1.1'; }
            public function withProtocolVersion(string $version): self { return $this; }
            public function getHeaders(): array { return $this->headers; }
            public function hasHeader(string $name): bool { return isset($this->headers[$name]); }
            public function getHeader(string $name): array { return $this->headers[$name] ?? []; }
            public function getHeaderLine(string $name): string { 
                return implode(', ', $this->getHeader($name));
            }
            public function withHeader(string $name, $value): self {
                $new = clone $this;
                $new->headers[$name] = is_array($value) ? $value : [$value];
                return $new;
            }
            public function withAddedHeader(string $name, $value): self { return $this; }
            public function withoutHeader(string $name): self { return $this; }
            public function getBody(): \Psr\Http\Message\StreamInterface {
                return new class($this->content) implements \Psr\Http\Message\StreamInterface {
                    private string $content;
                    private int $position = 0;
                    
                    public function __construct(string $content) { $this->content = $content; }
                    public function __toString(): string { return $this->content; }
                    public function close(): void {}
                    public function detach() { return null; }
                    public function getSize(): ?int { return strlen($this->content); }
                    public function tell(): int { return $this->position; }
                    public function eof(): bool { return $this->position >= strlen($this->content); }
                    public function isSeekable(): bool { return true; }
                    public function seek(int $offset, int $whence = SEEK_SET): void { $this->position = $offset; }
                    public function rewind(): void { $this->position = 0; }
                    public function isWritable(): bool { return true; }
                    public function write(string $string): int { $this->content = $string; return strlen($string); }
                    public function isReadable(): bool { return true; }
                    public function read(int $length): string { return substr($this->content, $this->position, $length); }
                    public function getContents(): string { return substr($this->content, $this->position); }
                    public function getMetadata(?string $key = null) { return null; }
                };
            }
            public function withBody(\Psr\Http\Message\StreamInterface $body): self { return $this; }
            public function getStatusCode(): int { return 200; }
            public function withStatus(int $code, string $reasonPhrase = ''): self { return $this; }
            public function getReasonPhrase(): string { return 'OK'; }
        };
        
        return $response;
    }

    private function createMockHandler(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);
        
        return $handler;
    }
}