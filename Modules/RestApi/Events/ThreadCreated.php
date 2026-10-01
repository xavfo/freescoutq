<?php

namespace Modules\RestApi\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Events\Dispatchable;

class ThreadCreated
{
    use Dispatchable;

    public $thread;
    public $user;

    public function __construct(\App\Thread $thread, \App\User $user)
    {
        $this->thread = $thread;
        $this->user = $user;
    }

    public function broadcastOn()
    {
        return new Channel('api.webhooks');
    }

    public function getWebhookPayload()
    {
        $types = [
            1 => 'customer',
            2 => 'message',
            3 => 'note',
            4 => 'lineitem',
            8 => 'chat',
        ];

        return [
            'event' => 'thread.created',
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'thread_id' => $this->thread->id,
                'conversation_id' => $this->thread->conversation_id,
                'type' => $types[$this->thread->type] ?? 'unknown',
                'body_preview' => substr($this->thread->body, 0, 200),
                'created_by' => $this->user->getFullName(),
            ],
        ];
    }
}
