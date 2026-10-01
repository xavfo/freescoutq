<?php

namespace Modules\RestApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreThreadRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'type' => 'in:2,3,8',
            'body' => 'required|string',
            'cc' => 'array',
            'cc.*' => 'email',
            'bcc' => 'array',
            'bcc.*' => 'email',
        ];
    }

    public function messages()
    {
        return [
            'type.in' => 'Invalid thread type (2=message, 3=note, 8=chat)',
            'body.required' => 'Body content is required',
            'cc.*.email' => 'Invalid CC email format',
            'bcc.*.email' => 'Invalid BCC email format',
        ];
    }
}
