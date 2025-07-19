<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Compression\Tests\Unit;

use HighPerApp\HighPer\Compression\Configuration\Configuration;
use PHPUnit\Framework\TestCase;

class ConfigurationTest extends TestCase
{
    private Configuration $config;

    protected function setUp(): void
    {
        $this->config = new Configuration();
    }

    public function testDefaultConfiguration(): void
    {
        $this->assertTrue($this->config->isEngineEnabled('rust_ffi'));
        $this->assertTrue($this->config->isEngineEnabled('pure_php'));
        $this->assertTrue($this->config->isEngineEnabled('amphp'));
        
        $this->assertEquals(1024, $this->config->getAsyncThreshold());
        $this->assertEquals(4, $this->config->getParallelWorkers());
        $this->assertEquals(6, $this->config->getCompressionQuality('brotli'));
        $this->assertEquals(6, $this->config->getCompressionQuality('gzip'));
        $this->assertFalse($this->config->isDebugEnabled());
    }

    public function testCustomConfiguration(): void
    {
        $customConfig = [
            'engines' => [
                'rust_ffi' => ['enabled' => false],
                'pure_php' => ['enabled' => true],
            ],
            'performance' => [
                'async_threshold' => 2048,
                'parallel_workers' => 8,
            ],
            'compression' => [
                'brotli' => ['quality' => 8],
                'gzip' => ['level' => 9],
            ],
            'debug' => ['enabled' => true],
        ];
        
        $config = new Configuration($customConfig);
        
        $this->assertFalse($config->isEngineEnabled('rust_ffi'));
        $this->assertTrue($config->isEngineEnabled('pure_php'));
        
        $this->assertEquals(2048, $config->getAsyncThreshold());
        $this->assertEquals(8, $config->getParallelWorkers());
        $this->assertEquals(8, $config->getCompressionQuality('brotli'));
        $this->assertEquals(9, $config->getCompressionQuality('gzip'));
        $this->assertTrue($config->isDebugEnabled());
    }

    public function testGetAndSet(): void
    {
        $this->config->set('test.key', 'test_value');
        $this->assertEquals('test_value', $this->config->get('test.key'));
        
        $this->config->set('nested.deep.key', 42);
        $this->assertEquals(42, $this->config->get('nested.deep.key'));
        
        $this->assertNull($this->config->get('non.existent.key'));
        $this->assertEquals('default', $this->config->get('non.existent.key', 'default'));
    }

    public function testHas(): void
    {
        $this->assertTrue($this->config->has('engines.rust_ffi.enabled'));
        $this->assertFalse($this->config->has('non.existent.key'));
        
        $this->config->set('test.key', null);
        $this->assertFalse($this->config->has('test.key'));
    }

    public function testMerge(): void
    {
        $originalAsyncThreshold = $this->config->getAsyncThreshold();
        
        $additionalConfig = [
            'performance' => [
                'async_threshold' => 4096,
                'new_setting' => 'new_value',
            ],
            'new_section' => [
                'key' => 'value',
            ],
        ];
        
        $this->config->merge($additionalConfig);
        
        $this->assertEquals(4096, $this->config->getAsyncThreshold());
        $this->assertEquals('new_value', $this->config->get('performance.new_setting'));
        $this->assertEquals('value', $this->config->get('new_section.key'));
    }

    public function testGetEngineConfig(): void
    {
        $rustConfig = $this->config->getEngineConfig('rust_ffi');
        
        $this->assertIsArray($rustConfig);
        $this->assertArrayHasKey('enabled', $rustConfig);
        $this->assertArrayHasKey('priority', $rustConfig);
        $this->assertArrayHasKey('algorithms', $rustConfig);
    }

    public function testGetCompressionConfig(): void
    {
        $brotliConfig = $this->config->getCompressionConfig('brotli');
        
        $this->assertIsArray($brotliConfig);
        $this->assertArrayHasKey('quality', $brotliConfig);
        $this->assertArrayHasKey('window_size', $brotliConfig);
        $this->assertArrayHasKey('mode', $brotliConfig);
    }

    public function testEnvironmentVariables(): void
    {
        // Save original values
        $originalValues = [];
        $envVars = [
            'COMPRESSION_PREFERRED_ENGINE',
            'COMPRESSION_ASYNC_THRESHOLD',
            'COMPRESSION_DEBUG',
        ];
        
        foreach ($envVars as $var) {
            $originalValues[$var] = $_ENV[$var] ?? null;
        }
        
        try {
            // Set environment variables
            $_ENV['COMPRESSION_PREFERRED_ENGINE'] = 'pure_php';
            $_ENV['COMPRESSION_ASYNC_THRESHOLD'] = '5000';
            $_ENV['COMPRESSION_DEBUG'] = 'true';
            
            $config = new Configuration();
            
            $this->assertEquals('pure_php', $config->getPreferredEngine());
            $this->assertEquals(5000, $config->getAsyncThreshold());
            $this->assertTrue($config->isDebugEnabled());
            
        } finally {
            // Restore original values
            foreach ($originalValues as $var => $value) {
                if ($value === null) {
                    unset($_ENV[$var]);
                } else {
                    $_ENV[$var] = $value;
                }
            }
        }
    }

    public function testAll(): void
    {
        $allConfig = $this->config->all();
        
        $this->assertIsArray($allConfig);
        $this->assertArrayHasKey('engines', $allConfig);
        $this->assertArrayHasKey('compression', $allConfig);
        $this->assertArrayHasKey('performance', $allConfig);
        $this->assertArrayHasKey('debug', $allConfig);
    }

    public function testCompressionQualityFallback(): void
    {
        $this->assertEquals(6, $this->config->getCompressionQuality('unknown_algorithm'));
    }

    public function testNestedConfigAccess(): void
    {
        $this->config->set('level1.level2.level3.value', 'deep_value');
        $this->assertEquals('deep_value', $this->config->get('level1.level2.level3.value'));
        
        $this->config->set('level1.level2.level3.other', 'other_value');
        $this->assertEquals('other_value', $this->config->get('level1.level2.level3.other'));
        $this->assertEquals('deep_value', $this->config->get('level1.level2.level3.value'));
    }

    public function testConfigurationImmutabilityOfDefaults(): void
    {
        $config1 = new Configuration();
        $config2 = new Configuration();
        
        $config1->set('engines.rust_ffi.enabled', false);
        
        // config2 should still have the default value
        $this->assertTrue($config2->isEngineEnabled('rust_ffi'));
    }
}