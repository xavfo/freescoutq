<?php

namespace Modules\RestApi\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Events\Dispatchable;

class ConversationStatusChanged
{
    use Dispatchable;

    public $conversation;
    public $oldStatus;
    public $newStatus;
    public $user;

    public function __construct(\App\Conversation $conversation, $oldStatus, $newStatus, \App\User $user)
    {
        $this->conversation = $conversation;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->user = $user;
    }

    public function broadcastOn()
    {
        return new Channel('api.webhooks');
    }

    public function getWebhookPayload()
    {
        $statuses = [
            1 => 'active',
            2 => 'pending',
            3 => 'closed',
            4 => 'spam',
        ];

        return [
            'event' => 'conversation.status_changed',
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'conversation_id' => $this->conversation->id,
                'subject' => $this->conversation->subject,
                'old_status' => $statuses[$this->oldStatus] ?? 'unknown',
                'new_status' => $statuses[$this->newStatus] ?? 'unknown',
                'changed_by' => $this->user->getFullName(),
            ],
        ];
    }
}
