<?php

namespace Modules\RestApi\Http\Requests;

use App\Conversation;
use Illuminate\Foundation\Http\FormRequest;
use Modules\RestApi\Support\Channels;

class StoreConversationRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        // Resolve the medium the same way the controller does: "channel"
        // (name) wins over "type" (number).
        $type = Channels::type($this->input('type', Conversation::TYPE_EMAIL));
        if ($this->filled('channel')) {
            $type = Channels::type($this->input('channel'));
        }

        $rules = [
            'mailbox_id' => 'required|integer|exists:mailboxes,id',
            'subject' => 'required|string|max:500',
            'body' => 'required|string',
            'type' => 'nullable|integer|in:' . implode(',', array_keys(Channels::all())),
            // Accept the channel by name too: "email", "whatsapp", ...
            'channel' => 'nullable|string|in:' . implode(',', array_values(Channels::all())),
            'status' => 'in:1,2,3,4',
            'customer_id' => 'integer|exists:customers,id',
            'assigned_to' => 'integer|exists:users,id',
            // Whether the message must be delivered to the customer
            // (email / WhatsApp). "send" is accepted as an alias.
            'send_message' => 'nullable|boolean',
            'send' => 'nullable|boolean',
            // Customer details for phone / WhatsApp conversations.
            'name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:64',
            'cc' => 'array',
            'cc.*' => 'email',
            'bcc' => 'array',
            'bcc.*' => 'email',
        ];

        if (Channels::requiresEmail($type)) {
            // Email conversations are identified by an email address.
            $rules['to'] = 'required|array|min:1';
            $rules['to.*'] = 'required|email';
        } else {
            // Phone / WhatsApp accept the number in "phone" or "to";
            // chat and custom have no external recipient.
            $rules['to'] = 'nullable|array';
            $rules['to.*'] = 'nullable|string';
        }

        return $rules;
    }

    public function messages()
    {
        return [
            'mailbox_id.required' => 'Mailbox ID is required',
            'mailbox_id.exists' => 'Mailbox does not exist',
            'subject.required' => 'Subject is required',
            'to.required' => 'At least one recipient email is required',
            'to.*.email' => 'Invalid email format',
            'assigned_to.exists' => 'Assignee user does not exist',
            'body.required' => 'Body content is required',
            'type.in' => 'Invalid conversation type',
            'channel.in' => 'Invalid channel (email, phone, chat, custom, whatsapp)',
        ];
    }
}
