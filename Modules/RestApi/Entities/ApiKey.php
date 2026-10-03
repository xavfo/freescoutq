<?php

namespace Modules\RestApi\Entities;

use Illuminate\Database\Eloquent\Model;
use Modules\RestApi\Support\MailboxAccess;

class ApiKey extends Model
{
    protected $table = 'api_keys';
    protected $fillable = ['user_id', 'name', 'token', 'token_hash', 'mailbox_ids', 'rate_limit', 'active', 'expires_at'];
    protected $casts = [
        'expires_at' => 'datetime',
    ];
    protected $hidden = ['token', 'token_hash'];

    /**
     * Normalize mailbox_ids on the way in.
     *
     * The ids are stored as a single JSON encoded array (or NULL for
     * "every mailbox"). Encoding here instead of in the callers avoids the
     * double encoding that corrupted legacy rows.
     *
     * @param mixed $value
     */
    public function setMailboxIdsAttribute($value)
    {
        $scope = MailboxAccess::normalize($value);

        $this->attributes['mailbox_ids'] = ($scope === null) ? null : json_encode($scope);
    }

    /**
     * Normalize mailbox_ids on the way out, so legacy (double encoded)
     * values are returned as a proper array too.
     *
     * @param  mixed $value
     * @return array|null
     */
    public function getMailboxIdsAttribute($value)
    {
        return MailboxAccess::normalize($value);
    }

    /**
     * Whether this token can access every mailbox.
     *
     * @return bool
     */
    public function hasAccessToAllMailboxes()
    {
        return $this->mailbox_ids === null;
    }

    /**
     * Whether this token can access the given mailbox.
     *
     * @param  int $mailboxId
     * @return bool
     */
    public function canAccessMailbox($mailboxId)
    {
        return MailboxAccess::allows($this->mailbox_ids, $mailboxId);
    }

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
