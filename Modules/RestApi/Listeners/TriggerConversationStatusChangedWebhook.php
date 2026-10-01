<?php

namespace Modules\RestApi\Listeners;

use Modules\RestApi\Events\ConversationStatusChanged;
use Modules\RestApi\Entities\ApiWebhook;
use Illuminate\Support\Facades\Queue;

class TriggerConversationStatusChangedWebhook
{
    public function handle(ConversationStatusChanged $event)
    {
        $webhooks = ApiWebhook::where('active', true)->get();
        $payload = $event->getWebhookPayload();

        foreach ($webhooks as $webhook) {
            if ($webhook->shouldTrigger('conversation.status_changed')) {
                Queue::push(function ($queue) use ($webhook, $payload) {
                    $webhook->trigger('conversation.status_changed', $payload);
                });
            }
        }
    }
}
