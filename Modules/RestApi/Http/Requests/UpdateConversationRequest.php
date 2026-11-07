<?php

namespace Modules\RestApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateConversationRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'status' => 'in:1,2,3,4',
            // null = unassigned; it must not be rejected by the integer rule.
            'assigned_to' => 'nullable|integer|exists:users,id',
            'customer_id' => 'integer|exists:customers,id',
            // Kanban stage id; __none removes the stage.
            'kanban_stage' => 'nullable|string|max:64',
        ];
    }

    public function messages()
    {
        return [
            'status.in' => 'Invalid status code',
            'assigned_to.exists' => 'User does not exist',
            'customer_id.exists' => 'Customer does not exist',
            'kanban_stage.max' => 'Kanban stage id is too long',
        ];
    }
}
