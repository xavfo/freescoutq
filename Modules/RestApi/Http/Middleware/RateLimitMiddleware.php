<?php

namespace Modules\RestApi\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RateLimitMiddleware
{
    public function handle($request, Closure $next)
    {
        $apiKeyId = $request->attributes->get('api_key_id');

        if (!$apiKeyId) {
            return $next($request);
        }

        // Get rate limit config
        $config = config('rest-api.api');
        $rateLimit = $config['rate_limit_default'] ?? 1000;
        $window = $config['rate_limit_window'] ?? 3600;

        // Get API key from database
        $apiKey = DB::table('api_keys')->find($apiKeyId);
        if ($apiKey) {
            $rateLimit = $apiKey->rate_limit ?? $rateLimit;
        }

        // Get current window key
        $windowStart = floor(time() / $window) * $window;
        $cacheKey = "api_rate_limit:{$apiKeyId}:{$windowStart}";

        // Increment request count
        $requestCount = Cache::get($cacheKey, 0);

        if ($requestCount >= $rateLimit) {
            $windowEnd = $windowStart + $window;
            $retryAfter = ceil($windowEnd - time());

            return new JsonResponse([
                'message' => 'Rate limit exceeded. Retry after ' . $retryAfter . ' seconds',
                'status_code' => 429,
                'retry_after' => $retryAfter,
            ], 429, ['Retry-After' => $retryAfter]);
        }

        // Set cache with expiration
        Cache::put($cacheKey, $requestCount + 1, $window);

        $response = $next($request);

        // Add rate limit headers
        $response->header('X-RateLimit-Limit', $rateLimit);
        $response->header('X-RateLimit-Remaining', $rateLimit - ($requestCount + 1));
        $response->header('X-RateLimit-Reset', $windowStart + $window);

        return $response;
    }
}
