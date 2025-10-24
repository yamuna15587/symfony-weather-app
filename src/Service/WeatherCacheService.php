<?php

namespace App\Service;

use App\Exception\RateLimitException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WeatherCacheService
{
    private const CACHE_TTL = 300; // 5 minutes
    private const MAX_RETRIES = 2;

    private HttpClientInterface $retryableClient;
    private string $apiBaseUrl;

    public function __construct(
        HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        string $apiBaseUrl,
    ) {
        $this->apiBaseUrl = rtrim($apiBaseUrl, '/');

        // Create retry strategy for 429 status code
        $retryStrategy = new GenericRetryStrategy(
            [429],
            1000,
            2.0,
            0,
            true
        );

        // Create the HTTP client with retry capability and max tries.
        $this->retryableClient = new RetryableHttpClient(
            client: $httpClient,
            strategy: $retryStrategy,
            maxRetries: self::MAX_RETRIES,
            logger: $logger
        );
    }

    /**
     * get the weather data from API with caching and retry logic.
     *
     * @param array $options Additional options for the HTTP request
     *
     * @return array Response data with metadata
     */
    public function fetchWeatherDataWithCache(array $options = []): array
    {
        $endpoint = '/forecast';

        // Build full URL from base URL and endpoint
        $url = $this->buildUrl($endpoint);
        $cacheKey = $this->generateCacheKey($url, $options);

        try {
            $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($url, $options, $cacheKey) {
                $item->expiresAfter(self::CACHE_TTL);

                $this->logger->info('Cache miss - Fetching from API', ['url' => $url]);

                try {
                    $apiResponse = $this->callApi($url, $options);

                    return [
                        'data' => $apiResponse,
                        'cached_at' => time(),
                        'source' => 'api',
                    ];
                } catch (RateLimitException $e) {
                    // Only for 429 errors after retries, try to get stale cache data
                    $staleData = $this->getStaleCache($cacheKey);
                    if (null !== $staleData) {
                        $this->logger->warning('Rate limit exceeded after retries - Using stale cache', [
                            'url' => $url,
                            'error' => $e->getMessage(),
                            'stale_age_seconds' => time() - $staleData['cached_at'],
                        ]);

                        return $staleData;
                    }
                    // No stale cache available for 429, throw the exception
                    throw $e;
                } catch (\Exception $e) {
                    // For other errors (500, etc.), always throw without checking stale cache
                    $this->logger->error('API call failed', [
                        'url' => $url,
                        'error' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            });

            // Add current source information
            $isCached = (time() - ($result['cached_at'] ?? 0)) > 0;
            $isStale = $isCached && (time() - $result['cached_at']) > self::CACHE_TTL;

            return [
                'data' => $result['data'],
                'source' => $isCached ? 'cache' : 'api',
                'is_stale' => $isStale,
                'cached_at' => $result['cached_at'] ?? null,
                'cache_expires_in' => max(0, self::CACHE_TTL - (time() - ($result['cached_at'] ?? time()))),
                'timestamp' => time(),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Error fetching data', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Failed to fetch data: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Call API using RetryableHttpClient.
     *
     * @throws RateLimitException For 429 errors
     * @throws \Exception         For other errors
     */
    private function callApi(string $url, array $options): mixed
    {
        try {
            $response = $this->retryableClient->request('GET', $url, $options);
            $statusCode = $response->getStatusCode();

            if (429 === $statusCode) {
                // 429 error - throw rate limit exception
                $this->logger->error('Rate limit exceeded', [
                    'url' => $url,
                    'status_code' => $statusCode,
                ]);

                throw new RateLimitException('Rate limit exceeded (429)');
            }

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('API call successful', [
                    'url' => $url,
                    'status_code' => $statusCode,
                ]);

                return $response->toArray();
            }

            // Handle other non-2xx responses
            throw new \RuntimeException('API returned status code: '.$statusCode);
        } catch (RateLimitException $e) {
            // Re-throw rate limit exceptions
            throw $e;
        } catch (\Exception $e) {
            // Check if this is a rate limit error based on message (fallback)
            if ($this->isRateLimitError($e)) {
                $this->logger->error('Rate limit exceeded after all retries', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);

                throw new RateLimitException('Rate limit exceeded: '.$e->getMessage(), 0, $e);
            }

            // For all other errors, throw as-is
            $this->logger->error('API call failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Check if exception is related to rate limiting (429) - fallback method.
     */
    private function isRateLimitError(\Exception $e): bool
    {
        // Check exception message for 429 status code as fallback
        $message = strtolower($e->getMessage());

        return str_contains($message, '429')
               || str_contains($message, 'too many requests')
               || str_contains($message, 'rate limit');
    }

    /**
     * Get stale cache data (even if expired).
     */
    private function getStaleCache(string $cacheKey): ?array
    {
        try {
            // Try to get the cached item even if expired
            $item = $this->cache->getItem($cacheKey);

            if ($item->isHit()) {
                $data = $item->get();

                // Verify it has the expected structure
                if (is_array($data) && isset($data['data'], $data['cached_at'])) {
                    return $data;
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug('Could not retrieve stale cache', [
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Generate a unique cache key based on URL and options.
     */
    private function generateCacheKey(string $url, array $options): string
    {
        $keyData = $url.json_encode($options);

        return 'weather_api_cache_'.md5($keyData);
    }

    /**
     * Build full URL from base URL and endpoint.
     */
    private function buildUrl(string $endpoint): string
    {
        // If endpoint is already a full URL, return as-is
        if (str_starts_with($endpoint, 'http://') || str_starts_with($endpoint, 'https://')) {
            return $endpoint;
        }

        // Remove leading slash from endpoint if present
        $endpoint = ltrim($endpoint, '/');

        return $this->apiBaseUrl.'/'.$endpoint;
    }
}
