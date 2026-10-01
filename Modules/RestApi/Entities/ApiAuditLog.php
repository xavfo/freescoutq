<?php

namespace Modules\RestApi\Entities;

use Illuminate\Database\Eloquent\Model;

class ApiAuditLog extends Model
{
    protected $table = 'api_audit_logs';
    protected $fillable = ['user_id', 'api_key_id', 'method', 'endpoint', 'query_parameters', 'response_code', 'response_time', 'response_message', 'ip_address'];
    protected $casts = [
        'query_parameters' => 'json',
    ];

    public function user()
    {
        return $this->belongsTo(\App\User::class);
    }

    public function apiKey()
    {
        return $this->belongsTo(ApiKey::class);
    }
}
