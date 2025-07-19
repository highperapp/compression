<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Compression\Tests\Performance;

use HighPerApp\HighPer\Compression\CompressionServiceProvider;
use PHPUnit\Framework\TestCase;

class CompressionPerformanceTest extends TestCase
{
    private const PERFORMANCE_THRESHOLD_COMPRESSION = 1.0; // seconds
    private const PERFORMANCE_THRESHOLD_DECOMPRESSION = 0.5; // seconds
    private const MIN_COMPRESSION_RATIO = 0.8; // 20% compression minimum
    
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
                ],
            ]
        );
    }

    public function testSmallDataPerformance(): void
    {
        $manager = $this->provider->bootstrap();
        $data = str_repeat('Small data performance test. ', 10);
        
        $this->performCompressionBenchmark($manager, $data, 'small');
    }

    public function testMediumDataPerformance(): void
    {
        $manager = $this->provider->bootstrap();
        $data = str_repeat('Medium data performance test. ', 500);
        
        $this->performCompressionBenchmark($manager, $data, 'medium');
    }

    public function testLargeDataPerformance(): void
    {
        $manager = $this->provider->bootstrap();
        $data = str_repeat('Large data performance test. This is a longer string with more content. ', 5000);
        
        $this->performCompressionBenchmark($manager, $data, 'large');
    }

    public function testRepeatedCompressionPerformance(): void
    {
        $manager = $this->provider->bootstrap();
        $data = str_repeat('Repeated compression test. ', 100);
        $iterations = 100;
        
        $totalCompressionTime = 0;
        $totalDecompressionTime = 0;
        
        for ($i = 0; $i < $iterations; $i++) {
            $startTime = microtime(true);
            $compressed = $manager->compress($data);
            $compressionTime = microtime(true) - $startTime;
            $totalCompressionTime += $compressionTime;
            
            $startTime = microtime(true);
            $decompressed = $manager->decompress($compressed);
            $decompressionTime = microtime(true) - $startTime;
            $totalDecompressionTime += $decompressionTime;
            
            $this->assertEquals($data, $decompressed);
        }
        
        $avgCompressionTime = $totalCompressionTime / $iterations;
        $avgDecompressionTime = $totalDecompressionTime / $iterations;
        
        echo "\nRepeated compression performance ({$iterations} iterations):\n";
        echo "Average compression time: " . number_format($avgCompressionTime * 1000, 2) . " ms\n";
        echo "Average decompression time: " . number_format($avgDecompressionTime * 1000, 2) . " ms\n";
        
        // Performance should be consistent
        $this->assertLessThan(0.1, $avgCompressionTime, 'Average compression time too high');
        $this->assertLessThan(0.05, $avgDecompressionTime, 'Average decompression time too high');
    }

    public function testDifferentAlgorithmPerformance(): void
    {
        $manager = $this->provider->bootstrap();
        $data = str_repeat('Algorithm performance comparison test. ', 200);
        $algorithms = ['gzip', 'deflate'];
        
        $results = [];
        
        foreach ($algorithms as $algorithm) {
            $startTime = microtime(true);
            $compressed = $manager->compress($data, $algorithm);
            $compressionTime = microtime(true) - $startTime;
            
            $startTime = microtime(true);
            $decompressed = $manager->decompress($compressed);
            $decompressionTime = microtime(true) - $startTime;
            
            $compressionRatio = strlen($compressed) / strlen($data);
            
            $results[$algorithm] = [
                'compression_time' => $compressionTime,
                'decompression_time' => $decompressionTime,
                'compression_ratio' => $compressionRatio,
                'compressed_size' => strlen($compressed),
            ];
            
            $this->assertEquals($data, $decompressed);
        }
        
        echo "\nAlgorithm performance comparison:\n";
        foreach ($results as $algorithm => $metrics) {
            echo sprintf(
                "%s: Compression: %.2f ms, Decompression: %.2f ms, Ratio: %.2f, Size: %d bytes\n",
                strtoupper($algorithm),
                $metrics['compression_time'] * 1000,
                $metrics['decompression_time'] * 1000,
                $metrics['compression_ratio'],
                $metrics['compressed_size']
            );
        }
    }

    public function testMemoryUsagePerformance(): void
    {
        $manager = $this->provider->bootstrap();
        
        $data = str_repeat('Memory usage test. ', 10000); // ~200KB
        
        $memoryBefore = memory_get_usage(true);
        $peakBefore = memory_get_peak_usage(true);
        
        $compressed = $manager->compress($data);
        $decompressed = $manager->decompress($compressed);
        
        $memoryAfter = memory_get_usage(true);
        $peakAfter = memory_get_peak_usage(true);
        
        $memoryUsed = $memoryAfter - $memoryBefore;
        $peakIncrease = $peakAfter - $peakBefore;
        
        echo "\nMemory usage performance:\n";
        echo "Data size: " . number_format(strlen($data)) . " bytes\n";
        echo "Memory used: " . number_format($memoryUsed) . " bytes\n";
        echo "Peak memory increase: " . number_format($peakIncrease) . " bytes\n";
        echo "Memory efficiency: " . number_format(($memoryUsed / strlen($data)) * 100, 2) . "%\n";
        
        $this->assertEquals($data, $decompressed);
        
        // Memory usage should be reasonable (less than 5x the data size)
        $this->assertLessThan(strlen($data) * 5, $memoryUsed, 'Memory usage too high');
    }

    public function testStreamPerformance(): void
    {
        $manager = $this->provider->bootstrap();
        
        $data = str_repeat('Stream performance test. ', 1000);
        
        // Create input stream
        $inputStream = fopen('php://memory', 'r+');
        fwrite($inputStream, $data);
        rewind($inputStream);
        
        $startTime = microtime(true);
        $compressedStream = $manager->compressStream($inputStream);
        $compressionTime = microtime(true) - $startTime;
        
        $startTime = microtime(true);
        $decompressedStream = $manager->decompressStream($compressedStream->getResource());
        $decompressedData = stream_get_contents($decompressedStream->getResource());
        $decompressionTime = microtime(true) - $startTime;
        
        echo "\nStream performance:\n";
        echo "Compression time: " . number_format($compressionTime * 1000, 2) . " ms\n";
        echo "Decompression time: " . number_format($decompressionTime * 1000, 2) . " ms\n";
        
        $this->assertEquals($data, $decompressedData);
        $this->assertLessThan(self::PERFORMANCE_THRESHOLD_COMPRESSION, $compressionTime);
        $this->assertLessThan(self::PERFORMANCE_THRESHOLD_DECOMPRESSION, $decompressionTime);
        
        fclose($inputStream);
    }

    public function testConcurrentCompressionPerformance(): void
    {
        $manager = $this->provider->bootstrap();
        $data = str_repeat('Concurrent compression test. ', 100);
        $concurrentTasks = 10;
        
        $startTime = microtime(true);
        
        $results = [];
        for ($i = 0; $i < $concurrentTasks; $i++) {
            $results[] = $manager->compress($data);
        }
        
        foreach ($results as $compressed) {
            $decompressed = $manager->decompress($compressed);
            $this->assertEquals($data, $decompressed);
        }
        
        $totalTime = microtime(true) - $startTime;
        $averageTime = $totalTime / $concurrentTasks;
        
        echo "\nConcurrent compression performance ({$concurrentTasks} tasks):\n";
        echo "Total time: " . number_format($totalTime * 1000, 2) . " ms\n";
        echo "Average time per task: " . number_format($averageTime * 1000, 2) . " ms\n";
        
        // Should complete all tasks in reasonable time
        $this->assertLessThan(2.0, $totalTime, 'Concurrent compression took too long');
    }

    public function testBenchmarkAccuracy(): void
    {
        $manager = $this->provider->bootstrap();
        
        $benchmarkResults = $manager->benchmarkEngines();
        $this->assertNotEmpty($benchmarkResults);
        
        foreach ($benchmarkResults as $engineName => $result) {
            if ($result['available']) {
                $this->assertGreaterThan(0, $result['score'], "Invalid benchmark score for {$engineName}");
                $this->assertIsArray($result['algorithms']);
                $this->assertNotEmpty($result['algorithms']);
                
                echo "\nEngine: {$engineName}\n";
                echo "Benchmark score: " . number_format($result['score'] * 1000, 2) . " ms\n";
                echo "Algorithms: " . implode(', ', $result['algorithms']) . "\n";
            }
        }
    }

    private function performCompressionBenchmark($manager, string $data, string $category): void
    {
        $startTime = microtime(true);
        $compressed = $manager->compress($data);
        $compressionTime = microtime(true) - $startTime;
        
        $startTime = microtime(true);
        $decompressed = $manager->decompress($compressed);
        $decompressionTime = microtime(true) - $startTime;
        
        $compressionRatio = strlen($compressed) / strlen($data);
        
        echo "\n{$category} data performance:\n";
        echo "Original size: " . number_format(strlen($data)) . " bytes\n";
        echo "Compressed size: " . number_format(strlen($compressed)) . " bytes\n";
        echo "Compression ratio: " . number_format($compressionRatio, 3) . "\n";
        echo "Compression time: " . number_format($compressionTime * 1000, 2) . " ms\n";
        echo "Decompression time: " . number_format($decompressionTime * 1000, 2) . " ms\n";
        echo "Compression speed: " . number_format(strlen($data) / $compressionTime / 1024, 2) . " KB/s\n";
        echo "Decompression speed: " . number_format(strlen($data) / $decompressionTime / 1024, 2) . " KB/s\n";
        
        $this->assertEquals($data, $decompressed);
        $this->assertLessThan(strlen($data), strlen($compressed), 'No compression achieved');
        $this->assertLessThan(self::MIN_COMPRESSION_RATIO, $compressionRatio, 'Compression ratio too low');
        $this->assertLessThan(self::PERFORMANCE_THRESHOLD_COMPRESSION, $compressionTime, 'Compression too slow');
        $this->assertLessThan(self::PERFORMANCE_THRESHOLD_DECOMPRESSION, $decompressionTime, 'Decompression too slow');
    }
}