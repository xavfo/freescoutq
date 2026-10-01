<?php

namespace Modules\RestApi\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Events\Dispatchable;

class ConversationCreated
{
    use Dispatchable;

    public $conversation;
    public $user;

    public function __construct(\App\Conversation $conversation, \App\User $user)
    {
        $this->conversation = $conversation;
        $this->user = $user;
    }

    public function broadcastOn()
    {
        return new Channel('api.webhooks');
    }

    public function getWebhookPayload()
    {
        return [
            'event' => 'conversation.created',
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'conversation_id' => $this->conversation->id,
                'subject' => $this->conversation->subject,
                'customer' => $this->conversation->customer ? $this->conversation->customer->getFullName() : null,
                'mailbox_id' => $this->conversation->mailbox_id,
                'created_by' => $this->user->getFullName(),
            ],
        ];
    }
}
