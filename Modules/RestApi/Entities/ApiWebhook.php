<?php

namespace Modules\RestApi\Entities;

use Illuminate\Database\Eloquent\Model;

class ApiWebhook extends Model
{
    protected $table = 'api_webhooks';
    protected $fillable = ['user_id', 'url', 'events', 'secret_key', 'active', 'retry_count', 'last_triggered_at', 'last_failed_at', 'last_error_message'];
    protected $casts = [
        'events' => 'json',
    ];

    public function user()
    {
        return $this->belongsTo(\App\User::class);
    }

    /**
     * Check if webhook should be triggered for event
     */
    public function shouldTrigger($event)
    {
        if (!$this->active) {
            return false;
        }

        return in_array($event, $this->events ?? []);
    }

    /**
     * Trigger webhook
     */
    public function trigger($event, $payload)
    {
        if (!$this->shouldTrigger($event)) {
            return false;
        }

        try {
            $signature = hash_hmac('sha256', json_encode($payload), $this->secret_key);

            $response = \Http::withHeaders([
                'X-Webhook-Signature' => $signature,
                'X-Webhook-Event' => $event,
            ])
                ->timeout(config('rest-api.webhooks.timeout', 10))
                ->post($this->url, $payload);

            if ($response->successful()) {
                $this->update([
                    'last_triggered_at' => now(),
                    'retry_count' => 0,
                    'last_error_message' => null,
                ]);
                return true;
            }

            throw new \Exception('HTTP ' . $response->status());
        } catch (\Exception $e) {
            $this->increment('retry_count');
            $this->update([
                'last_failed_at' => now(),
                'last_error_message' => $e->getMessage(),
            ]);

            // Retry logic handled elsewhere
            return false;
        }
    }
}
