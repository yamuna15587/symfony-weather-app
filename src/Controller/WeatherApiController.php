<?php

namespace App\Controller;

use App\Service\WeatherCacheService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;

class WeatherApiController extends AbstractController
{
    public function __construct(
        private WeatherCacheService $weatherCacheService
    ) {
    }

    #[Route('/api/getweatherdata', name: 'api_weather_data', methods: ['GET'])]
    public function getWeatherData(Request $request): JsonResponse
    {
        // Extract and validate latitude and longitude from request parameters
        $latitude = $request->query->get('latitude', '52.52');
        $longitude = $request->query->get('longitude', '13.41');

        if (!is_numeric($latitude) || $latitude < -90 || $latitude > 90) {
            return new JsonResponse([
                'error' => 'Invalid latitude. Must be between -90 and 90.'
            ], Response::HTTP_BAD_REQUEST);
        }
        
        // Validate longitude range (-180 to 180)
        if (!is_numeric($longitude) || $longitude < -180 || $longitude > 180) {
            return new JsonResponse([
                'error' => 'Invalid longitude. Must be between -180 and 180.'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Prepare params to get the weather data

        $options = [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'hourly' => 'temperature_2m',
            'current' => 'temperature_2m',
            'forecast_days' => 1
        ];


        try {
            // Now just pass the endpoint path, base URL comes from env
            $result = $this->weatherCacheService->fetchWeatherDataWithCache($options);


            return $this->json([
                'success' => true,
                'source' => $result['source'],
                'cached_at' => $result['cached_at'] 
                    ? date('Y-m-d H:i:s', $result['cached_at']) 
                    : null,
                'cache_expires_in_seconds' => $result['cache_expires_in'],
                'timestamp' => date('Y-m-d H:i:s', $result['timestamp']),
                'message' => $result['source'] === 'cache' 
                    ? 'Data retrieved from cache' 
                    : 'Data retrieved from API',
                'data' => $result['data']
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}