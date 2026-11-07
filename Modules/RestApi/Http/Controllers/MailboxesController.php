<?php

namespace Modules\RestApi\Http\Controllers;

use App\Mailbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\RestApi\Support\Kanban;
use Modules\RestApi\Support\MailboxAccess;

class MailboxesController extends Controller
{
    /**
     * Etapas Kanban configuradas para un buzón.
     *
     * GET /api/v1/mailboxes/:id/kanban-stages
     *
     * La columna virtual "Sin etapa" (`__none`) siempre viene primero para que
     * el cliente pueda ofrecerla como destino sin inventarse el identificador.
     */
    public function kanbanStages(Request $request, $id): JsonResponse
    {
        $scope = $request->attributes->get('mailbox_ids');

        $mailbox = Mailbox::find($id);

        if (!$mailbox) {
            return response()->json([
                'message' => 'Mailbox not found',
                'status_code' => 404,
            ], 404);
        }

        if (!MailboxAccess::allows($scope, $mailbox->id)) {
            return response()->json([
                'message' => 'Unauthorized to access this mailbox',
                'status_code' => 403,
            ], 403);
        }

        if (!Kanban::isAvailable()) {
            return response()->json([
                'message' => __('Kanban module is not active'),
                'status_code' => 422,
                'error' => Kanban::ERROR_NOT_AVAILABLE,
            ], 422);
        }

        return response()->json([
            'data' => Kanban::stages($mailbox),
            'meta' => [
                'mailbox_id' => $mailbox->id,
            ],
        ], 200);
    }
}
