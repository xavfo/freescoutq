<?php

namespace Modules\RestApi\Http\Controllers;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\Thread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\RestApi\Entities\DTOs\ConversationDTO;
use Modules\RestApi\Http\Requests\StoreConversationRequest;
use Modules\RestApi\Http\Requests\UpdateConversationRequest;
use Modules\RestApi\Support\LogsApiAudit;
use Modules\RestApi\Support\MailboxAccess;

class ConversationsController extends Controller
{
    use LogsApiAudit;

    /**
     * List conversations
     * GET /api/v1/conversations
     */
    public function index(Request $request): JsonResponse
    {
        // Already normalized by CheckApiTokenMiddleware:
        // null = every mailbox, array = authorized mailboxes.
        $scope = $request->attributes->get('mailbox_ids');

        $query = Conversation::query();

        MailboxAccess::applyScope($query, 'mailbox_id', $scope);

        // Apply filters
        if ($request->has('status')) {
            $statuses = array_filter(array_map('trim', explode(',', (string) $request->input('status'))));
            $query->whereIn('status', array_map('intval', $statuses));
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        if ($request->has('mailbox_id')) {
            $query->where('mailbox_id', $request->input('mailbox_id'));
        }

        if ($request->has('assigned_to')) {
            $query->where('user_id', $request->input('assigned_to'));
        }

        // Sort
        $sort = $request->input('sort', '-created_at');
        $direction = strncmp($sort, '-', 1) === 0 ? 'desc' : 'asc';
        $sortField = ltrim($sort, '-');
        $query->orderBy($sortField, $direction);

        // Paginate
        $perPage = min($request->input('per_page', 20), 100);
        $page = $request->input('page', 1);
        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        // Transform data
        $data = $paginated->items();
        $data = array_map(function ($conv) {
            return ConversationDTO::fromModel($conv)->toArray();
        }, $data);

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $paginated->total(),
                'page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'last_page' => $paginated->lastPage(),
            ],
            'links' => [
                'first' => $paginated->url(1),
                'last' => $paginated->url($paginated->lastPage()),
                'next' => $paginated->nextPageUrl(),
                'prev' => $paginated->previousPageUrl(),
            ],
        ], 200);
    }

    /**
     * Get single conversation
     * GET /api/v1/conversations/:id
     */
    public function show(Request $request, $id): JsonResponse
    {
        $scope = $request->attributes->get('mailbox_ids');

        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json([
                'message' => 'Conversation not found',
                'status_code' => 404,
            ], 404);
        }

        // Check authorization
        if (!MailboxAccess::allows($scope, $conversation->mailbox_id)) {
            return response()->json([
                'message' => 'Unauthorized to access this conversation',
                'status_code' => 403,
            ], 403);
        }

        $dto = ConversationDTO::fromModel($conversation)->toArray();

        return response()->json($dto, 200);
    }

    /**
     * Create conversation
     * POST /api/v1/conversations
     */
    public function store(StoreConversationRequest $request): JsonResponse
    {
        $userId = $request->attributes->get('user_id');
        $scope = $request->attributes->get('mailbox_ids');

        $mailboxId = (int) $request->input('mailbox_id');

        // A token without mailbox_ids has access to every mailbox,
        // a token with a list is limited to those mailboxes.
        if (!MailboxAccess::allows($scope, $mailboxId)) {
            return response()->json([
                'message' => 'Unauthorized to create conversation in this mailbox',
                'status_code' => 403,
            ], 403);
        }

        $mailbox = Mailbox::find($mailboxId);
        if (!$mailbox) {
            return response()->json([
                'message' => 'Mailbox not found',
                'status_code' => 404,
            ], 404);
        }

        // array_values() because sanitizeEmails() may remove invalid entries
        // without reindexing the list.
        $to = array_values(Conversation::sanitizeEmails($request->input('to', [])));
        $cc = array_values(Conversation::sanitizeEmails($request->input('cc', [])));
        $bcc = array_values(Conversation::sanitizeEmails($request->input('bcc', [])));

        if (empty($to)) {
            return response()->json([
                'message' => 'At least one valid recipient email is required',
                'status_code' => 422,
            ], 422);
        }

        try {
            $customer = null;

            if ($request->filled('customer_id')) {
                $customer = Customer::find($request->input('customer_id'));
            }

            if (!$customer) {
                // FreeScout keeps customer emails in the "emails" table,
                // Customer::create() links the address to the customer.
                $customer = Customer::getByEmail($to[0]);

                if (!$customer) {
                    $customer = Customer::create($to[0]);
                }
            }

            if (!$customer) {
                return response()->json([
                    'message' => 'Unable to resolve or create the customer',
                    'status_code' => 422,
                ], 422);
            }

            // Assignee: optional "assigned_to", token owner by default.
            $assigneeId = $request->filled('assigned_to')
                ? (int) $request->input('assigned_to')
                : ($userId ? (int) $userId : null);

            $conversation = new Conversation();
            $conversation->mailbox_id = $mailbox->id;
            $conversation->customer_id = $customer->id;
            $conversation->customer_email = $to[0];
            $conversation->type = (int) $request->input('type', Conversation::TYPE_EMAIL);
            $conversation->status = (int) $request->input('status', Conversation::STATUS_ACTIVE);
            $conversation->state = Conversation::STATE_PUBLISHED;
            $conversation->subject = $request->input('subject');
            $conversation->user_id = $assigneeId;
            $conversation->created_by_user_id = $userId ? (int) $userId : null;
            // source_via / source_type are required and have no database default.
            $conversation->source_via = Conversation::PERSON_USER;
            $conversation->source_type = Conversation::SOURCE_TYPE_API;
            // folder_id is guarded and has no database default.
            $conversation->updateFolder($mailbox);
            $conversation->save();

            // Thread is guarded, so it must be built through Thread::create(),
            // which also fills status/state/source_via/source_type.
            $thread = Thread::create($conversation, Thread::TYPE_MESSAGE, $request->input('body'), [
                'user_id' => $assigneeId,
                'created_by_user_id' => $userId ? (int) $userId : null,
                'customer_id' => $customer->id,
                'source_via' => Thread::PERSON_USER,
                'source_type' => Thread::SOURCE_TYPE_API,
                'from' => $mailbox->name . ' <' . $mailbox->email . '>',
                'to' => $to,
                'cc' => $cc,
                'bcc' => $bcc,
            ]);

            $thread->first = true;
            $thread->save();

            $this->logAudit($request, 'created', $conversation->id, 201);

            // Reload so the response exposes the values written by ThreadObserver
            // (threads_count, preview, last_reply_at).
            $conversation = Conversation::find($conversation->id);

            return response()->json(
                ConversationDTO::fromModel($conversation)->toArray(),
                201
            );
        } catch (\Exception $e) {
            \Log::error('RestApi: error creating conversation: ' . $e->getMessage());

            return response()->json([
                'message' => 'Error creating conversation: ' . $e->getMessage(),
                'status_code' => 500,
            ], 500);
        }
    }

    /**
     * Update conversation
     * PUT /api/v1/conversations/:id
     */
    public function update(UpdateConversationRequest $request, $id): JsonResponse
    {
        $scope = $request->attributes->get('mailbox_ids');

        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json([
                'message' => 'Conversation not found',
                'status_code' => 404,
            ], 404);
        }

        // Check authorization
        if (!MailboxAccess::allows($scope, $conversation->mailbox_id)) {
            return response()->json([
                'message' => 'Unauthorized to update this conversation',
                'status_code' => 403,
            ], 403);
        }

        try {
            if ($request->has('status')) {
                $conversation->status = (int) $request->input('status');
            }

            if ($request->has('assigned_to')) {
                $conversation->user_id = $request->input('assigned_to');
            }

            if ($request->has('customer_id')) {
                $conversation->customer_id = $request->input('customer_id');
            }

            $conversation->updateFolder();
            $conversation->save();

            $this->logAudit($request, 'updated', $conversation->id);

            return response()->json(
                ConversationDTO::fromModel($conversation)->toArray(),
                200
            );
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error updating conversation: ' . $e->getMessage(),
                'status_code' => 500,
            ], 500);
        }
    }

    /**
     * Delete conversation (soft delete)
     * DELETE /api/v1/conversations/:id
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $scope = $request->attributes->get('mailbox_ids');

        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json([
                'message' => 'Conversation not found',
                'status_code' => 404,
            ], 404);
        }

        // Check authorization
        if (!MailboxAccess::allows($scope, $conversation->mailbox_id)) {
            return response()->json([
                'message' => 'Unauthorized to delete this conversation',
                'status_code' => 403,
            ], 403);
        }

        try {
            $conversation->state = Conversation::STATE_DELETED;
            $conversation->updateFolder();
            $conversation->save();

            $this->logAudit($request, 'deleted', $conversation->id, 204);

            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error deleting conversation: ' . $e->getMessage(),
                'status_code' => 500,
            ], 500);
        }
    }
}
