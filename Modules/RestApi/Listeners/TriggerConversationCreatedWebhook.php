<?php

namespace Modules\RestApi\Listeners;

use Modules\RestApi\Events\ConversationCreated;
use Modules\RestApi\Entities\ApiWebhook;
use Illuminate\Support\Facades\Queue;

class TriggerConversationCreatedWebhook
{
    public function handle(ConversationCreated $event)
    {
        $webhooks = ApiWebhook::where('active', true)->get();
        $payload = $event->getWebhookPayload();

        foreach ($webhooks as $webhook) {
            if ($webhook->shouldTrigger('conversation.created')) {
                Queue::push(function ($queue) use ($webhook, $payload) {
                    $webhook->trigger('conversation.created', $payload);
                });
            }
        }
    }
}
