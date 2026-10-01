<?php

namespace Modules\RestApi\Entities\DTOs;

use App\Thread;

class ThreadDTO
{
    public $id;
    public $conversation_id;
    public $type;
    public $type_name;
    public $from;
    public $to;
    public $cc;
    public $bcc;
    public $body;
    public $attachments_count;
    public $created_by_type; // 'user' or 'customer'
    public $created_by_name;
    public $created_at;
    public $updated_at;

    /**
     * Create from Thread model
     */
    public static function fromModel(Thread $thread)
    {
        $instance = new self();

        $instance->id = $thread->id;
        $instance->conversation_id = $thread->conversation_id;
        $instance->type = $thread->type;
        $instance->type_name = self::getTypeName($thread->type);
        $instance->from = $thread->from;
        $instance->to = $thread->to;
        $instance->cc = $thread->cc;
        $instance->bcc = $thread->bcc;
        $instance->body = $thread->body;
        $instance->attachments_count = $thread->attachments()->count() ?? 0;
        $instance->created_by_type = $thread->customer_id ? 'customer' : 'user';
        $instance->created_by_name = $thread->customer
            ? $thread->customer->getFullName()
            : ($thread->user ? $thread->user->getFullName() : 'System');
        $instance->created_at = $thread->created_at->toIso8601String();
        $instance->updated_at = $thread->updated_at->toIso8601String();

        return $instance;
    }

    /**
     * Convert to array
     */
    public function toArray()
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'type' => $this->type,
            'type_name' => $this->type_name,
            'from' => $this->from,
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'body' => $this->body,
            'attachments_count' => $this->attachments_count,
            'created_by' => [
                'type' => $this->created_by_type,
                'name' => $this->created_by_name,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Get type name from code
     */
    protected static function getTypeName($type)
    {
        $types = [
            1 => 'customer',
            2 => 'message',
            3 => 'note',
            4 => 'lineitem',
            8 => 'chat',
        ];

        return $types[$type] ?? 'unknown';
    }
}
