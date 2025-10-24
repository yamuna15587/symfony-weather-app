# symfony-weather-app

Weather Cache Proxy for Initrode
A Symfony-based caching proxy for the Open-Meteo API, designed to prevent rate limiting (429 errors)

Solution
This application provides a caching layer that:

Accepts weather forecast requests
Caches API responses for 5 minutes to reduce frequent hits to api
Implements automatic retry logic for 429 errors (up to 3 attempts with exponential backoff)
Supports configurable latitude/longitude parameters
Always returns either cached or fresh data

Installation
bash
# Clone the repository
`git clone repository-url`
`cd project-directory`

# Install dependencies
composer install

# Configure environment
cp .env.example .env

# Start the development server
symfony server:start

Usage
API Endpoint
GET /api/getweatherdata

Query Parameters
latitude (optional, float): Latitude coordinate (-90 to 90). Default: 52.52 
longitude (optional, float): Longitude coordinate (-180 to 180). Default: 13.41 
Example Requests
bash
# Default location
curl http://localhost:8000/api/getweatherdata

# Custom location
curl "http://localhost:8000/api/getweatherdata?latitude=51.51&longitude=-0.13"

Response Format

json
{
  "latitude": 52.52,
  "longitude": 13.41,
  "generationtime_ms": 0.123,
  "utc_offset_seconds": 0,
  "timezone": "GMT",
  "timezone_abbreviation": "GMT",
  "elevation": 38.0,
  "current_units": {
    "time": "iso8601",
    "interval": "seconds",
    "temperature_2m": "°C"
  },
  "current": {
    "time": "2025-10-22T10:00",
    "interval": 900,
    "temperature_2m": 15.2
  },
  "hourly_units": {
    "time": "iso8601",
    "temperature_2m": "°C"
  },
  "hourly": {
    "time": ["2025-10-22T00:00", "2025-10-22T01:00", ...],
    "temperature_2m": [12.1, 11.8, ...]
  }
}
Error Responses
400 Bad Request - Invalid coordinates

json
{
  "error": "Invalid latitude. Must be between -90 and 90."
}
429 Too Many Requests - Rate limit still hit after retries

json
{
  "error": "Weather service is currently rate limited. Please try again in a few minutes."
}
503 Service Unavailable - Network/connection error

json
{
  "error": "Unable to connect to weather service. Please try again later."
}

Testing
The application includes comprehensive PHPUnit tests:

bash
# Run all tests
./vendor/bin/phpunit

Configuration
Cache Backend
Edit config/packages/cache.yaml to use different cache adapters



