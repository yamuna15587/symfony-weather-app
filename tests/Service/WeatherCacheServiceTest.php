<?php

namespace App\Tests\Service;

use App\Exception\RateLimitException;
use App\Service\WeatherCacheService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class WeatherCacheServiceTest extends TestCase
{
    private CacheInterface $cache;
    private LoggerInterface $logger;
    private WeatherCacheService $service;
    private array $options;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->options = [
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'hourly' => 'temperature_2m',
            'current' => 'temperature_2m',
            'forecast_days' => 1,
        ];
    }

    public function testFetchWeatherDataWithCacheReturnsApiDataOnCacheMiss(): void
    {
        $responseData = ['temperature_2m' => 25];
        $mockResponse = new MockResponse(json_encode($responseData), [
            'http_headers' => ['Content-Type' => 'application/json'],
            'http_code' => 200,
        ]);

        $httpClient = new MockHttpClient($mockResponse);

        $this->service = new WeatherCacheService(
            $httpClient,
            $this->cache,
            $this->logger,
            'https://api.example.com'
        );

        // Simulate cache miss
        $this->cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->method('expiresAfter')->willReturnSelf();
                return $callback($item);
            });

        $result = $this->service->fetchWeatherDataWithCache($this->options);

        $this->assertEquals('api', $result['source']);
        $this->assertEquals($responseData, $result['data']);
    }

    public function testFetchWeatherDataWithCacheReturnsCachedData(): void
    {
        $cachedData = [
            'data' => ['temperature_2m' => 22],
            'cached_at' => time() - 100,
            'source' => 'cache',
        ];

        $mockResponse = new MockResponse(json_encode(['temperature_2m' => 25]), ['http_code' => 200]);
        $httpClient = new MockHttpClient($mockResponse);

        $this->service = new WeatherCacheService(
            $httpClient,
            $this->cache,
            $this->logger,
            'https://api.example.com'
        );

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn($cachedData);

        $result = $this->service->fetchWeatherDataWithCache($this->options);

        $this->assertEquals('cache', $result['source']);
        $this->assertEquals($cachedData['data'], $result['data']);
    }

    public function testFetchWeatherDataWithCacheUsesStaleCacheOnRateLimit(): void
    {
        $staleData = [
            'data' => ['temperature_2m' => 20],
            'cached_at' => time() - 200,
            'source' => 'cache',
        ];

        $mockResponse = new MockResponse('', ['http_code' => 429]); // simulate rate limit
        $httpClient = new MockHttpClient($mockResponse);

        $this->service = new WeatherCacheService(
            $httpClient,
            $this->cache,
            $this->logger,
            'https://api.example.com'
        );

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) use ($staleData) {
                try {
                    throw new RateLimitException('Rate limit exceeded (429)');
                } catch (RateLimitException $e) {
                    return $staleData;
                }
            });

        $result = $this->service->fetchWeatherDataWithCache($this->options);

        $this->assertEquals('cache', $result['source']);
        $this->assertEquals($staleData['data'], $result['data']);
    }
}
