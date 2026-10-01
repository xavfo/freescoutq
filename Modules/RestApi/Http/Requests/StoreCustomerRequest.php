<?php

namespace Modules\RestApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'first_name' => 'required|string|max:255',
            'last_name' => 'string|max:255',
            'emails' => 'required|array|min:1',
            'emails.*' => 'email',
            'phone' => 'string|max:20',
        ];
    }

    public function messages()
    {
        return [
            'first_name.required' => 'First name is required',
            'emails.required' => 'At least one email is required',
            'emails.*.email' => 'Invalid email format',
        ];
    }
}
