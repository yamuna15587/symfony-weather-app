<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use App\Service\WeatherCacheService;

class WeatherApiControllerTest extends WebTestCase
{
    private $client;
    private $mockWeatherService;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Create mock WeatherCacheService
        $this->mockWeatherService = $this->createMock(WeatherCacheService::class);

        // Replace the real service with mock in container
        static::getContainer()->set(WeatherCacheService::class, $this->mockWeatherService);
    }

    public function testGetWeatherDataSuccessFromApi(): void
    {
        $mockData = [
            'data' => ['temperature_2m' => [12.3]],
            'source' => 'api',
            'is_stale' => false,
            'cached_at' => time(),
            'cache_expires_in' => 300,
            'timestamp' => time()
        ];

        $this->mockWeatherService
            ->method('fetchWeatherDataWithCache')
            ->willReturn($mockData);

        $this->client->request('GET', '/api/getweatherdata?latitude=52.52&longitude=13.41');

        $response = $this->client->getResponse();

        $this->assertResponseIsSuccessful();
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertEquals('Data retrieved from API', $data['message']);
        $this->assertArrayHasKey('data', $data);
    }

    public function testInvalidLatitudeReturnsBadRequest(): void
    {
        $this->client->request('GET', '/api/getweatherdata?latitude=999&longitude=13.41');

        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testInvalidLongitudeReturnsBadRequest(): void
    {
        $this->client->request('GET', '/api/getweatherdata?latitude=52.52&longitude=999');

        $response = $this->client->getResponse();
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testServiceThrowsExceptionReturns500(): void
    {
        $this->mockWeatherService
            ->method('fetchWeatherDataWithCache')
            ->willThrowException(new \RuntimeException('API failed'));

        $this->client->request('GET', '/api/getweatherdata?latitude=52.52&longitude=13.41');

        $response = $this->client->getResponse();

        $this->assertEquals(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('API failed', $data['error']);
    }
}
