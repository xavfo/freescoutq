<?php

namespace Modules\RestApi\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CheckApiTokenMiddleware
{
    public function handle($request, Closure $next)
    {
        // Get token from Authorization header
        $authHeader = $request->header('Authorization');

        if (!$authHeader || !preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
            return $this->errorResponse('Missing or invalid Authorization header', 401);
        }

        $token = $matches[1];

        // Find API key in database
        $apiKey = DB::table('api_keys')
            ->where('active', true)
            ->get()
            ->first(function ($key) use ($token) {
                return hash_equals($key->token_hash, hash('sha256', $token));
            });

        if (!$apiKey) {
            return $this->errorResponse('Invalid API token', 401);
        }

        // Check expiration
        if ($apiKey->expires_at && now()->isAfter($apiKey->expires_at)) {
            return $this->errorResponse('API token has expired', 401);
        }

        // Update last_used_at
        DB::table('api_keys')
            ->where('id', $apiKey->id)
            ->update(['last_used_at' => now()]);

        // Store in request for later use
        $request->attributes->set('api_key_id', $apiKey->id);
        $request->attributes->set('user_id', $apiKey->user_id);
        $request->attributes->set(
            'mailbox_ids',
            $apiKey->mailbox_ids ? json_decode($apiKey->mailbox_ids, true) : null
        );

        // Log the API call
        try {
            DB::table('api_audit_logs')->insert([
                'user_id' => $apiKey->user_id,
                'api_key_id' => $apiKey->id,
                'method' => $request->getMethod(),
                'endpoint' => $request->path(),
                'query_parameters' => json_encode($request->query()),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Silently fail logging
        }

        return $next($request);
    }

    private function errorResponse($message, $statusCode)
    {
        return new JsonResponse([
            'message' => $message,
            'status_code' => $statusCode,
        ], $statusCode);
    }
}
