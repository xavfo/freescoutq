<?php

namespace Modules\RestApi\Entities\DTOs;

use App\Customer;

class CustomerDTO
{
    public $id;
    public $first_name;
    public $last_name;
    public $emails;
    public $phone;
    public $photo_url;
    public $created_at;
    public $conversations_count;

    /**
     * Create from Customer model
     */
    public static function fromModel(Customer $customer)
    {
        $instance = new self();

        $instance->id = $customer->id;
        $instance->first_name = $customer->first_name;
        $instance->last_name = $customer->last_name;
        // Emails live in the "emails" relation, not in a customers column.
        $instance->emails = $customer->emails()
            ->orderBy('id')
            ->pluck('email')
            ->all();
        // Phones are stored as a JSON column.
        $instance->phone = $customer->getMainPhoneNumber();
        $instance->photo_url = $customer->photo_url;
        $instance->created_at = $customer->created_at->toIso8601String();
        $instance->conversations_count = $customer->conversations()->count();

        return $instance;
    }

    /**
     * Convert to array
     */
    public function toArray()
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => trim($this->first_name . ' ' . $this->last_name),
            'emails' => $this->emails,
            'phone' => $this->phone,
            'photo_url' => $this->photo_url,
            'created_at' => $this->created_at,
            'conversations_count' => $this->conversations_count,
        ];
    }
}
