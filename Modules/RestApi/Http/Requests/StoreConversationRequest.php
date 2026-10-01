<?php

namespace Modules\RestApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreConversationRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'mailbox_id' => 'required|integer|exists:mailboxes,id',
            'subject' => 'required|string|max:500',
            'to' => 'required|array|min:1',
            'to.*' => 'required|email',
            'cc' => 'array',
            'cc.*' => 'email',
            'bcc' => 'array',
            'bcc.*' => 'email',
            'customer_id' => 'integer|exists:customers,id',
            'body' => 'required|string',
            'type' => 'in:1,2,3,4',
            'status' => 'in:1,2,3,4',
        ];
    }

    public function messages()
    {
        return [
            'mailbox_id.required' => 'Mailbox ID is required',
            'mailbox_id.exists' => 'Mailbox does not exist',
            'subject.required' => 'Subject is required',
            'to.required' => 'At least one recipient email is required',
            'to.*.email' => 'Invalid email format',
            'body.required' => 'Body content is required',
        ];
    }
}
