<?php

namespace Modules\RestApi\Listeners;

use Modules\RestApi\Events\ThreadCreated;
use Modules\RestApi\Entities\ApiWebhook;
use Illuminate\Support\Facades\Queue;

class TriggerThreadCreatedWebhook
{
    public function handle(ThreadCreated $event)
    {
        $webhooks = ApiWebhook::where('active', true)->get();
        $payload = $event->getWebhookPayload();

        foreach ($webhooks as $webhook) {
            if ($webhook->shouldTrigger('thread.created')) {
                Queue::push(function ($queue) use ($webhook, $payload) {
                    $webhook->trigger('thread.created', $payload);
                });
            }
        }
    }
}
