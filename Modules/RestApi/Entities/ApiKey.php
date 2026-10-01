<?php

namespace Modules\RestApi\Entities;

use Illuminate\Database\Eloquent\Model;

class ApiKey extends Model
{
    protected $table = 'api_keys';
    protected $fillable = ['user_id', 'name', 'token', 'token_hash', 'mailbox_ids', 'rate_limit', 'active', 'expires_at'];
    protected $casts = [
        'mailbox_ids' => 'json',
        'expires_at' => 'datetime',
    ];
    protected $hidden = ['token', 'token_hash'];

    public function user()
    {
        return $this->belongsTo(\App\User::class);
    }

    public function webhooks()
    {
        return $this->hasMany(ApiWebhook::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(ApiAuditLog::class);
    }

    /**
     * Generate a new API token
     */
    public static function generateToken()
    {
        return 'fs_' . bin2hex(random_bytes(32));
    }
}
