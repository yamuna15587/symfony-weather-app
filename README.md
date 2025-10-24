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
`composer install`

# Configure environment
`cp .env.example .env`

# Start the development server
`symfony server:start`

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

Testing
The application includes comprehensive PHPUnit tests:

bash
# Run all tests
`php ./vendor/bin/phpunit`

Configuration
Cache Backend
Edit config/packages/cache.yaml to use different cache adapters

PHP Cs Fixer
The application includes phpcsfixer package:
# Command to format files according to PSR standards
`php ./vendor/bin/php-cs-fixer fix src`

Installation Through Docker
The application includes docker compose configuration and container setup:
`docker compose up -d --build`
To run composer or php commands
`docker exec -it weatherapp_php /bin/bash`
Run all the composer/php commnads in this terminal.



