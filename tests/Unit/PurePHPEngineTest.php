<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Compression\Tests\Unit;

use HighPerApp\HighPer\Compression\Engines\PurePHPEngine;
use HighPerApp\HighPer\Compression\Exceptions\CompressionException;
use PHPUnit\Framework\TestCase;

class PurePHPEngineTest extends TestCase
{
    private PurePHPEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new PurePHPEngine();
    }

    public function testIsAvailable(): void
    {
        $this->assertTrue($this->engine->isAvailable());
    }

    public function testGetName(): void
    {
        $this->assertEquals('pure_php', $this->engine->getName());
    }

    public function testGetSupportedAlgorithms(): void
    {
        $algorithms = $this->engine->getSupportedAlgorithms();
        $this->assertIsArray($algorithms);
        $this->assertContains('gzip', $algorithms);
        $this->assertContains('deflate', $algorithms);
    }

    public function testGzipCompression(): void
    {
        $data = str_repeat('Hello, World! This is a test string for gzip compression. ', 10);
        
        $compressed = $this->engine->compress($data, ['algorithm' => 'gzip']);
        $this->assertNotEmpty($compressed);
        $this->assertLessThan(strlen($data), strlen($compressed));
        
        $decompressed = $this->engine->decompress($compressed);
        $this->assertEquals($data, $decompressed);
    }

    public function testDeflateCompression(): void
    {
        $data = str_repeat('Hello, World! This is a test string for deflate compression. ', 10);
        
        $compressed = $this->engine->compress($data, ['algorithm' => 'deflate']);
        $this->assertNotEmpty($compressed);
        
        $decompressed = $this->engine->decompress($compressed);
        $this->assertEquals($data, $decompressed);
    }

    public function testBrotliCompression(): void
    {
        $data = str_repeat('Hello, World! This is a test string for brotli compression. ', 10);
        
        if (extension_loaded('brotli')) {
            $compressed = $this->engine->compress($data, ['algorithm' => 'brotli']);
            $this->assertNotEmpty($compressed);
            
            $decompressed = $this->engine->decompress($compressed);
            $this->assertEquals($data, $decompressed);
        } else {
            // Should fallback to gzip
            $compressed = $this->engine->compress($data, ['algorithm' => 'brotli']);
            $this->assertNotEmpty($compressed);
            
            $decompressed = $this->engine->decompress($compressed);
            $this->assertEquals($data, $decompressed);
        }
    }

    public function testCompressionLevels(): void
    {
        $data = str_repeat('Compression level test data. ', 50);
        
        $levels = [1, 6, 9];
        $sizes = [];
        
        foreach ($levels as $level) {
            $compressed = $this->engine->compress($data, [
                'algorithm' => 'gzip',
                'level' => $level,
            ]);
            $sizes[$level] = strlen($compressed);
            
            $decompressed = $this->engine->decompress($compressed);
            $this->assertEquals($data, $decompressed);
        }
        
        // Higher compression levels should generally produce smaller output
        $this->assertLessThanOrEqual($sizes[1], $sizes[6]);
        $this->assertLessThanOrEqual($sizes[6], $sizes[9]);
    }

    public function testStreamCompression(): void
    {
        $data = str_repeat('Stream compression test. ', 100);
        
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, $data);
        rewind($inputStream);
        
        $compressedStream = $this->engine->compressStream($inputStream, ['algorithm' => 'gzip']);
        $this->assertIsObject($compressedStream);
        
        $decompressedStream = $this->engine->decompressStream($compressedStream->getResource());
        $decompressedData = stream_get_contents($decompressedStream->getResource());
        
        $this->assertEquals($data, $decompressedData);
        
        fclose($inputStream);
    }

    public function testEstimateCompressionRatio(): void
    {
        $dataSize = 10000;
        
        $ratioGzip = $this->engine->estimateCompressionRatio($dataSize, 'gzip');
        $ratioDeflate = $this->engine->estimateCompressionRatio($dataSize, 'deflate');
        $ratioBrotli = $this->engine->estimateCompressionRatio($dataSize, 'brotli');
        
        $this->assertIsFloat($ratioGzip);
        $this->assertIsFloat($ratioDeflate);
        $this->assertIsFloat($ratioBrotli);
        
        $this->assertGreaterThan(0, $ratioGzip);
        $this->assertLessThan(1, $ratioGzip);
        
        $this->assertGreaterThan(0, $ratioDeflate);
        $this->assertLessThan(1, $ratioDeflate);
        
        $this->assertGreaterThan(0, $ratioBrotli);
        $this->assertLessThan(1, $ratioBrotli);
    }

    public function testGetBenchmarkScore(): void
    {
        $score = $this->engine->getBenchmarkScore();
        $this->assertIsFloat($score);
        $this->assertGreaterThan(0, $score);
    }

    public function testGetPerformanceMetrics(): void
    {
        // Perform some operations to generate metrics
        $data = 'Test data for performance metrics';
        $this->engine->compress($data, ['algorithm' => 'gzip']);
        $this->engine->compress($data, ['algorithm' => 'deflate']);
        
        $metrics = $this->engine->getPerformanceMetrics();
        
        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('total_compressions', $metrics);
        $this->assertArrayHasKey('total_decompressions', $metrics);
        $this->assertArrayHasKey('engine_version', $metrics);
        $this->assertArrayHasKey('available_extensions', $metrics);
        
        $this->assertGreaterThanOrEqual(2, $metrics['total_compressions']);
    }

    public function testErrorHandling(): void
    {
        // Test unsupported algorithm
        $this->expectException(CompressionException::class);
        $this->engine->compress('test data', ['algorithm' => 'unsupported']);
    }

    public function testEmptyDataHandling(): void
    {
        $compressed = $this->engine->compress('', ['algorithm' => 'gzip']);
        $decompressed = $this->engine->decompress($compressed);
        
        $this->assertEquals('', $decompressed);
    }

    public function testLargeDataCompression(): void
    {
        $largeData = str_repeat('Large data compression test. ', 1000);
        
        $compressed = $this->engine->compress($largeData, ['algorithm' => 'gzip']);
        $decompressed = $this->engine->decompress($compressed);
        
        $this->assertEquals($largeData, $decompressed);
        $this->assertLessThan(strlen($largeData), strlen($compressed));
    }

    public function testAutoDecompression(): void
    {
        $data = 'Auto decompression test data';
        
        // Test with gzip
        $gzipCompressed = $this->engine->compress($data, ['algorithm' => 'gzip']);
        $gzipDecompressed = $this->engine->decompress($gzipCompressed);
        $this->assertEquals($data, $gzipDecompressed);
        
        // Test with deflate  
        $deflateCompressed = $this->engine->compress($data, ['algorithm' => 'deflate']);
        $deflateDecompressed = $this->engine->decompress($deflateCompressed);
        $this->assertEquals($data, $deflateDecompressed);
    }
}