<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Compression\Middleware;

use HighPerApp\HighPer\Compression\Contracts\CompressionManagerInterface;
use HighPerApp\HighPer\Compression\Contracts\ConfigurationInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class CompressionMiddleware implements MiddlewareInterface
{
    private CompressionManagerInterface $compressionManager;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private array $compressibleTypes = [
        'text/html',
        'text/css',
        'text/javascript',
        'text/plain',
        'application/javascript',
        'application/json',
        'application/xml',
        'application/rss+xml',
        'application/atom+xml',
        'image/svg+xml',
    ];

    public function __construct(
        CompressionManagerInterface $compressionManager,
        ConfigurationInterface $config,
        LoggerInterface $logger = null
    ) {
        $this->compressionManager = $compressionManager;
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        // Skip compression if not applicable
        if (!$this->shouldCompress($request, $response)) {
            return $response;
        }

        try {
            return $this->compressResponse($request, $response);
        } catch (\Throwable $e) {
            $this->logger->error('Response compression failed', [
                'error' => $e->getMessage(),
                'uri' => (string) $request->getUri(),
            ]);
            
            // Return original response on compression failure
            return $response;
        }
    }

    public function addCompressibleType(string $mimeType): void
    {
        if (!in_array($mimeType, $this->compressibleTypes, true)) {
            $this->compressibleTypes[] = $mimeType;
        }
    }

    public function removeCompressibleType(string $mimeType): void
    {
        $key = array_search($mimeType, $this->compressibleTypes, true);
        if ($key !== false) {
            unset($this->compressibleTypes[$key]);
            $this->compressibleTypes = array_values($this->compressibleTypes);
        }
    }

    public function setCompressibleTypes(array $mimeTypes): void
    {
        $this->compressibleTypes = $mimeTypes;
    }

    private function shouldCompress(ServerRequestInterface $request, ResponseInterface $response): bool
    {
        // Check if compression is already applied
        if ($response->hasHeader('Content-Encoding')) {
            return false;
        }

        // Check response size
        $body = $response->getBody();
        $size = $body->getSize();
        
        if ($size !== null && $size < 1024) {
            return false; // Don't compress small responses
        }

        // Check content type
        $contentType = $response->getHeaderLine('Content-Type');
        if (!$this->isCompressibleContentType($contentType)) {
            return false;
        }

        // Check client support
        $acceptedEncodings = $this->getAcceptedEncodings($request);
        if (empty($acceptedEncodings)) {
            return false;
        }

        // Check security constraints
        if ($size !== null && $size > $this->config->get('security.max_input_size', 50 * 1024 * 1024)) {
            $this->logger->warning('Response too large for compression', ['size' => $size]);
            return false;
        }

        return true;
    }

    private function compressResponse(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $acceptedEncodings = $this->getAcceptedEncodings($request);
        $algorithm = $this->selectCompressionAlgorithm($acceptedEncodings);
        
        if ($algorithm === null) {
            return $response;
        }

        $body = $response->getBody();
        $content = (string) $body;
        
        if (empty($content)) {
            return $response;
        }

        $startTime = microtime(true);
        
        // Determine compression options
        $options = $this->getCompressionOptions($algorithm, strlen($content));
        
        // Compress the content
        $compressedContent = $this->compressionManager->compress($content, $algorithm, $options);
        
        $compressionTime = microtime(true) - $startTime;
        $originalSize = strlen($content);
        $compressedSize = strlen($compressedContent);
        $compressionRatio = $compressedSize / $originalSize;
        
        // Log compression metrics
        $this->logger->info('Response compressed', [
            'algorithm' => $algorithm,
            'original_size' => $originalSize,
            'compressed_size' => $compressedSize,
            'compression_ratio' => $compressionRatio,
            'compression_time' => $compressionTime,
            'engine' => $this->compressionManager->getPreferredEngine(),
        ]);

        // Create new response with compressed content
        $body->rewind();
        $body->write($compressedContent);
        
        return $response
            ->withHeader('Content-Encoding', $this->getContentEncodingHeader($algorithm))
            ->withHeader('Content-Length', (string) $compressedSize)
            ->withHeader('Vary', 'Accept-Encoding')
            ->withHeader('X-Compression-Engine', $this->compressionManager->getPreferredEngine())
            ->withHeader('X-Compression-Ratio', sprintf('%.2f', $compressionRatio));
    }

    private function getAcceptedEncodings(ServerRequestInterface $request): array
    {
        $acceptEncoding = $request->getHeaderLine('Accept-Encoding');
        
        if (empty($acceptEncoding)) {
            return [];
        }

        $encodings = [];
        $parts = explode(',', $acceptEncoding);
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/^([a-z\-]+)(?:;q=([0-9\.]+))?$/i', $part, $matches)) {
                $encoding = strtolower($matches[1]);
                $quality = isset($matches[2]) ? (float) $matches[2] : 1.0;
                
                if ($quality > 0) {
                    $encodings[$encoding] = $quality;
                }
            }
        }

        // Sort by quality (highest first)
        arsort($encodings);
        
        return array_keys($encodings);
    }

    private function selectCompressionAlgorithm(array $acceptedEncodings): ?string
    {
        $supportedAlgorithms = [];
        
        foreach ($this->compressionManager->getAvailableEngines() as $engine) {
            $supportedAlgorithms = array_merge($supportedAlgorithms, $engine->getSupportedAlgorithms());
        }
        
        $supportedAlgorithms = array_unique($supportedAlgorithms);
        
        // Priority order for algorithms
        $algorithmPriority = ['br', 'gzip', 'deflate'];
        
        foreach ($algorithmPriority as $algorithm) {
            $encodingName = $this->getEncodingName($algorithm);
            
            if (in_array($encodingName, $acceptedEncodings, true) && 
                in_array($algorithm, $supportedAlgorithms, true)) {
                return $algorithm;
            }
        }
        
        return null;
    }

    private function getCompressionOptions(string $algorithm, int $contentSize): array
    {
        $options = [
            'algorithm' => $algorithm,
        ];

        // Set quality/level based on content size and algorithm
        switch ($algorithm) {
            case 'brotli':
                $options['quality'] = $contentSize > 10240 ? 6 : 4;
                $options['window_size'] = 22;
                break;
                
            case 'gzip':
            case 'deflate':
                $options['level'] = $contentSize > 10240 ? 6 : 4;
                break;
        }

        // Enable parallel processing for large content
        if ($contentSize > $this->config->getAsyncThreshold()) {
            $options['parallel'] = true;
        }

        return $options;
    }

    private function isCompressibleContentType(string $contentType): bool
    {
        if (empty($contentType)) {
            return false;
        }

        // Extract the main content type (remove charset, etc.)
        $mainType = explode(';', $contentType)[0];
        $mainType = trim(strtolower($mainType));

        return in_array($mainType, $this->compressibleTypes, true);
    }

    private function getEncodingName(string $algorithm): string
    {
        return match ($algorithm) {
            'brotli' => 'br',
            'gzip' => 'gzip',
            'deflate' => 'deflate',
            default => $algorithm,
        };
    }

    private function getContentEncodingHeader(string $algorithm): string
    {
        return $this->getEncodingName($algorithm);
    }
}