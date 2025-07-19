<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Compression\Tests\Unit;

use HighPerApp\HighPer\Compression\CompressionManager;
use HighPerApp\HighPer\Compression\Configuration\Configuration;
use HighPerApp\HighPer\Compression\Contracts\CompressionEngineInterface;
use HighPerApp\HighPer\Compression\Exceptions\CompressionException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CompressionManagerTest extends TestCase
{
    private CompressionManager $manager;
    private Configuration $config;

    protected function setUp(): void
    {
        $this->config = new Configuration([
            'engines' => [
                'pure_php' => [
                    'enabled' => true,
                    'priority' => 1,
                    'algorithms' => ['gzip', 'deflate'],
                ],
                'amphp' => [
                    'enabled' => false,
                ],
                'rust_ffi' => [
                    'enabled' => false,
                ],
            ],
            'preferred_engine' => 'pure_php',
        ]);
        
        $this->manager = new CompressionManager($this->config, new NullLogger());
    }

    public function testCompressAndDecompress(): void
    {
        $originalData = str_repeat('Hello, World! This is a test string for compression. ', 20);
        
        $compressed = $this->manager->compress($originalData, 'gzip');
        $this->assertNotEmpty($compressed);
        $this->assertLessThan(strlen($originalData), strlen($compressed));
        
        $decompressed = $this->manager->decompress($compressed);
        $this->assertEquals($originalData, $decompressed);
    }

    public function testAutoAlgorithmSelection(): void
    {
        $smallData = 'Small data';
        $mediumData = str_repeat('Medium sized data for testing. ', 50);
        $largeData = str_repeat('Large data set for compression testing purposes. ', 500);
        
        $smallAlgorithm = $this->manager->getOptimalAlgorithm(strlen($smallData));
        $mediumAlgorithm = $this->manager->getOptimalAlgorithm(strlen($mediumData));
        $largeAlgorithm = $this->manager->getOptimalAlgorithm(strlen($largeData));
        
        $this->assertIsString($smallAlgorithm);
        $this->assertIsString($mediumAlgorithm);
        $this->assertIsString($largeAlgorithm);
    }

    public function testGetAvailableEngines(): void
    {
        $engines = $this->manager->getAvailableEngines();
        $this->assertNotEmpty($engines);
        
        foreach ($engines as $engine) {
            $this->assertInstanceOf(CompressionEngineInterface::class, $engine);
            $this->assertTrue($engine->isAvailable());
        }
    }

    public function testBenchmarkEngines(): void
    {
        $benchmarks = $this->manager->benchmarkEngines();
        $this->assertNotEmpty($benchmarks);
        
        foreach ($benchmarks as $engineName => $benchmark) {
            $this->assertIsString($engineName);
            $this->assertArrayHasKey('score', $benchmark);
            $this->assertArrayHasKey('available', $benchmark);
            $this->assertArrayHasKey('algorithms', $benchmark);
            $this->assertIsFloat($benchmark['score']);
            $this->assertIsBool($benchmark['available']);
            $this->assertIsArray($benchmark['algorithms']);
        }
    }

    public function testCompressionStats(): void
    {
        $data = 'Test data for statistics';
        
        // Perform some compressions
        $this->manager->compress($data, 'gzip');
        $this->manager->compress($data, 'gzip');
        
        $stats = $this->manager->getCompressionStats();
        
        $this->assertArrayHasKey('total_operations', $stats);
        $this->assertArrayHasKey('total_compression_time', $stats);
        $this->assertArrayHasKey('operations_by_engine', $stats);
        $this->assertArrayHasKey('operations_by_algorithm', $stats);
        
        $this->assertGreaterThanOrEqual(2, $stats['total_operations']);
    }

    public function testSetPreferredEngine(): void
    {
        $originalEngine = $this->manager->getPreferredEngine();
        
        $this->manager->setPreferredEngine('pure_php');
        $this->assertEquals('pure_php', $this->manager->getPreferredEngine());
        
        // Test invalid engine
        $this->expectException(\HighPerApp\HighPer\Compression\Exceptions\EngineNotAvailableException::class);
        $this->manager->setPreferredEngine('non_existent_engine');
    }

    public function testStreamCompression(): void
    {
        $data = str_repeat('Stream compression test data. ', 100);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $data);
        rewind($stream);
        
        $compressedStream = $this->manager->compressStream($stream, 'gzip');
        $this->assertIsResource($compressedStream->getResource());
        
        fclose($stream);
    }

    public function testErrorHandling(): void
    {
        // Test with empty data
        $result = $this->manager->compress('');
        $this->assertEquals('', $this->manager->decompress($result));
        
        // Test with invalid compressed data
        $this->expectException(CompressionException::class);
        $this->manager->decompress('invalid compressed data');
    }

    public function testDifferentAlgorithms(): void
    {
        $data = 'Test data for different algorithms';
        $algorithms = ['gzip', 'deflate'];
        
        foreach ($algorithms as $algorithm) {
            $compressed = $this->manager->compress($data, $algorithm);
            $decompressed = $this->manager->decompress($compressed);
            
            $this->assertEquals($data, $decompressed, "Failed for algorithm: {$algorithm}");
        }
    }

    public function testLargeDataCompression(): void
    {
        $largeData = str_repeat('Large data compression test. ', 1000);
        
        $compressed = $this->manager->compress($largeData);
        $decompressed = $this->manager->decompress($compressed);
        
        $this->assertEquals($largeData, $decompressed);
        $this->assertLessThan(strlen($largeData), strlen($compressed));
    }
}