<?php

namespace Modules\RestApi\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Updates the audit log row created by CheckApiTokenMiddleware.
 *
 * The middleware stores the id of the row it inserted in the request
 * attributes, so each controller updates its own request instead of
 * overwriting the most recent log of the API key.
 */
trait LogsApiAudit
{
    /**
     * @param  Request    $request
     * @param  string     $action       Short description, e.g. "updated".
     * @param  int|null   $subjectId    Id of the affected model.
     * @param  int        $responseCode
     * @return void
     */
    protected function logAudit(Request $request, $action, $subjectId = null, $responseCode = 200)
    {
        try {
            $auditLogId = $request->attributes->get('api_audit_log_id');

            if (!$auditLogId) {
                return;
            }

            $message = trim(static::class . ' ' . $action . ($subjectId ? ' #' . $subjectId : ''));

            DB::table('api_audit_logs')
                ->where('id', $auditLogId)
                ->update([
                    'response_code' => $responseCode,
                    'response_message' => $message,
                    'updated_at' => now(),
                ]);
        } catch (\Exception $e) {
            // Audit logging must never break the request.
        }
    }
}
