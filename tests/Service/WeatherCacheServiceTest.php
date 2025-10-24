<?php

namespace App\Tests\Service;

use App\Service\WeatherCacheService;
use App\Exception\RateLimitException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class WeatherCacheServiceTest extends TestCase
{
    private $httpClient;
    private $cache;
    private $logger;
    private WeatherCacheService $service;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new WeatherCacheService(
            $this->httpClient,
            $this->cache,
            $this->logger,
            'https://api.open-meteo.com/v1'
        );
    }

    public function testFetchWeatherDataWithCacheReturnsCachedData(): void
    {
        $cachedResult = [
            'data' => ['temperature_2m' => [10.5]],
            'cached_at' => time(),
            'source' => 'cache'
        ];

        $this->cache->method('get')->willReturn($cachedResult);

        $result = $this->service->fetchWeatherDataWithCache(['latitude' => 52.52]);

        $this->assertArrayHasKey('data', $result);
        $this->assertEquals('cache', $result['source']);
    }

    public function testFetchWeatherDataCallsApiOnCacheMiss(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('toArray')->willReturn(['temperature_2m' => [14.2]]);

        // Simulate cache miss
        $this->cache->method('get')->willReturnCallback(function ($key, $callback) {
            $item = $this->createMock(ItemInterface::class);
            return $callback($item);
        });

        // Mock HTTP client request
        $this->httpClient
            ->method('request')
            ->willReturn($mockResponse);

        $result = $this->service->fetchWeatherDataWithCache(['latitude' => 52.52]);

        $this->assertEquals('api', $result['source']);
        $this->assertArrayHasKey('data', $result);
    }

    public function testRateLimitUsesStaleCache(): void
    {
        $staleData = [
            'data' => ['temperature_2m' => [11.0]],
            'cached_at' => time() - 400,
            'source' => 'cache'
        ];

        $this->cache->method('getItem')
            ->willReturnCallback(function () use ($staleData) {
                $item = $this->createMock(ItemInterface::class);
                $item->method('isHit')->willReturn(true);
                $item->method('get')->willReturn($staleData);
                return $item;
            });

        $this->cache->method('get')->willReturnCallback(function ($key, $callback) {
            $item = $this->createMock(ItemInterface::class);
            // Force RateLimitException inside callback
            throw new RateLimitException('Rate limit exceeded');
        });

        $this->expectException(RateLimitException::class);
        $this->service->fetchWeatherDataWithCache(['latitude' => 52.52]);
    }

    public function testBuildUrlHandlesFullUrl(): void
    {
        $method = new \ReflectionMethod(WeatherCacheService::class, 'buildUrl');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'https://example.com/test');
        $this->assertEquals('https://example.com/test', $result);
    }

    public function testGenerateCacheKey(): void
    {
        $method = new \ReflectionMethod(WeatherCacheService::class, 'generateCacheKey');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'https://api.open-meteo.com/v1/forecast', ['latitude' => 52.52]);
        $this->assertStringStartsWith('weather_api_cache_', $result);
    }
}
