<?php

namespace Modules\RestApi\Entities\DTOs;

use App\Conversation;
use Modules\RestApi\Support\Channels;

class ConversationDTO
{
    public $id;
    public $mailbox_id;
    public $subject;
    public $preview;
    public $customer_name;
    public $customer_email;
    public $status;
    public $status_name;
    public $state;
    public $type;
    public $type_name;
    public $channel;
    public $threads_count;
    public $assignee_id;
    public $assignee_name;
    public $created_at;
    public $updated_at;
    public $last_message_preview;

    /**
     * Create from Conversation model
     */
    public static function fromModel(Conversation $conversation)
    {
        $instance = new self();

        $instance->id = $conversation->id;
        $instance->mailbox_id = $conversation->mailbox_id;
        $instance->subject = $conversation->subject;
        $instance->preview = $conversation->preview;
        $instance->customer_name = $conversation->customer ? $conversation->customer->getFullName() : 'Unknown';
        $instance->customer_email = $conversation->customer ? $conversation->customer->getMainEmail() : null;
        $instance->status = $conversation->status;
        $instance->status_name = self::getStatusName($conversation->status);
        $instance->state = $conversation->state;
        $instance->type = $conversation->type;
        $instance->type_name = Conversation::typeToName($conversation->type);
        $instance->channel = Channels::name($conversation->type);
        $instance->threads_count = $conversation->threads_count ?? 0;
        $instance->assignee_id = $conversation->user_id;
        $instance->assignee_name = $conversation->user ? $conversation->user->getFullName() : null;
        $instance->created_at = $conversation->created_at->toIso8601String();
        $instance->updated_at = $conversation->updated_at->toIso8601String();
        $lastThread = $conversation->threads()->latest()->first();
        $instance->last_message_preview = $lastThread ? $lastThread->body : '';

        return $instance;
    }

    /**
     * Convert to array
     */
    public function toArray()
    {
        return [
            'id' => $this->id,
            'mailbox_id' => $this->mailbox_id,
            'subject' => $this->subject,
            'preview' => $this->preview,
            'customer' => [
                'name' => $this->customer_name,
                'email' => $this->customer_email,
            ],
            'status' => $this->status,
            'status_name' => $this->status_name,
            'state' => $this->state,
            'type' => $this->type,
            'type_name' => $this->type_name,
            'channel' => $this->channel,
            'threads_count' => $this->threads_count,
            'assignee' => [
                'id' => $this->assignee_id,
                'name' => $this->assignee_name,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'last_message_preview' => $this->last_message_preview,
        ];
    }

    /**
     * Get status name from code
     */
    protected static function getStatusName($status)
    {
        $statuses = [
            1 => 'active',
            2 => 'pending',
            3 => 'closed',
            4 => 'spam',
        ];

        return $statuses[$status] ?? 'unknown';
    }
}
