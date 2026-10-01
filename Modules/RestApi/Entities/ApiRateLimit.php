<?php

namespace Modules\RestApi\Entities;

use Illuminate\Database\Eloquent\Model;

class ApiRateLimit extends Model
{
    protected $table = 'api_rate_limits';
    protected $fillable = ['api_key_id', 'request_count', 'window_starts_at'];
    protected $casts = [
        'window_starts_at' => 'datetime',
    ];

    public function apiKey()
    {
        return $this->belongsTo(ApiKey::class);
    }
}
