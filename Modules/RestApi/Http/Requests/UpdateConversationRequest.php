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
            'assigned_to' => 'integer|exists:users,id',
            'customer_id' => 'integer|exists:customers,id',
        ];
    }

    public function messages()
    {
        return [
            'status.in' => 'Invalid status code',
            'assigned_to.exists' => 'User does not exist',
            'customer_id.exists' => 'Customer does not exist',
        ];
    }
}
