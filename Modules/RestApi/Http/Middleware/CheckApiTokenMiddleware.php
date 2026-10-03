<?php

namespace Modules\RestApi\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\RestApi\Support\MailboxAccess;

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
        $tokenHash = hash('sha256', $token);

        // Find API key in database
        $apiKey = DB::table('api_keys')
            ->where('active', true)
            ->get()
            ->first(function ($key) use ($tokenHash) {
                return is_string($key->token_hash) && hash_equals($key->token_hash, $tokenHash);
            });

        if (!$apiKey) {
            return $this->errorResponse('Invalid API token', 401);
        }

        // Check expiration.
        //
        // gt() is used instead of isAfter(): the Carbon version bundled with
        // this FreeScout release does not implement isAfter(), and calling it
        // raised "Method isAfter does not exist" (HTTP 500) whenever an
        // expired token was used, instead of the expected 401.
        if ($apiKey->expires_at && now()->gt($apiKey->expires_at)) {
            return $this->errorResponse('API token has expired', 401);
        }

        // Update last_used_at
        DB::table('api_keys')
            ->where('id', $apiKey->id)
            ->update(['last_used_at' => now()]);

        // Store in request for later use.
        //
        // mailbox_ids is normalized into either null (access to every mailbox)
        // or an array of integers. Handlers must never receive the raw database
        // value, otherwise authorization checks break (see MailboxAccess).
        $request->attributes->set('api_key_id', $apiKey->id);
        $request->attributes->set('user_id', $apiKey->user_id);
        $request->attributes->set('mailbox_ids', MailboxAccess::normalize($apiKey->mailbox_ids));

        // Log the API call
        try {
            $auditLogId = DB::table('api_audit_logs')->insertGetId([
                'user_id' => $apiKey->user_id,
                'api_key_id' => $apiKey->id,
                'method' => $request->getMethod(),
                'endpoint' => $request->path(),
                'query_parameters' => json_encode($request->query()),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Handlers update this exact row instead of guessing "the latest
            // log of this API key", which used to overwrite concurrent requests.
            $request->attributes->set('api_audit_log_id', $auditLogId);
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
