<?php

namespace Modules\RestApi\Http\Controllers;

use App\Conversation;
use App\Mailbox;
use App\Thread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\RestApi\Entities\DTOs\ThreadDTO;
use Modules\RestApi\Http\Requests\StoreThreadRequest;
use Modules\RestApi\Support\Channels;
use Modules\RestApi\Support\LogsApiAudit;
use Modules\RestApi\Support\MailboxAccess;

class ThreadsController extends Controller
{
    use LogsApiAudit;

    /**
     * Get threads for a conversation
     * GET /api/v1/conversations/:id/threads
     */
    public function index(Request $request, $conversationId): JsonResponse
    {
        $scope = $request->attributes->get('mailbox_ids');

        $conversation = Conversation::find($conversationId);

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

        $query = Thread::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'asc');

        // Paginate
        $perPage = min($request->input('per_page', 50), 200);
        $page = $request->input('page', 1);
        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        // Transform data
        $data = array_map(fn($thread) => ThreadDTO::fromModel($thread)->toArray(), $paginated->items());

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $paginated->total(),
                'page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'last_page' => $paginated->lastPage(),
            ],
        ], 200);
    }

    /**
     * Get single thread
     * GET /api/v1/threads/:id
     */
    public function show(Request $request, $id): JsonResponse
    {
        $scope = $request->attributes->get('mailbox_ids');

        $thread = Thread::with('conversation')->find($id);

        if (!$thread) {
            return response()->json([
                'message' => 'Thread not found',
                'status_code' => 404,
            ], 404);
        }

        // Check authorization
        if (!MailboxAccess::allows($scope, optional($thread->conversation)->mailbox_id)) {
            return response()->json([
                'message' => 'Unauthorized to access this thread',
                'status_code' => 403,
            ], 403);
        }

        return response()->json(ThreadDTO::fromModel($thread)->toArray(), 200);
    }

    /**
     * Add thread to conversation
     * POST /api/v1/conversations/:id/threads
     */
    public function store(StoreThreadRequest $request, $conversationId): JsonResponse
    {
        $userId = $request->attributes->get('user_id');
        $scope = $request->attributes->get('mailbox_ids');

        $conversation = Conversation::find($conversationId);

        if (!$conversation) {
            return response()->json([
                'message' => 'Conversation not found',
                'status_code' => 404,
            ], 404);
        }

        // Check authorization
        if (!MailboxAccess::allows($scope, $conversation->mailbox_id)) {
            return response()->json([
                'message' => 'Unauthorized to add thread to this conversation',
                'status_code' => 403,
            ], 403);
        }

        // The medium is defined by the conversation itself.
        $type = (int) $conversation->type;
        $threadType = (int) $request->input('type', Thread::TYPE_MESSAGE);

        // Notes are internal and are never delivered. For deliverable channels
        // (email / WhatsApp) the reply is sent by default when the caller does
        // not specify it explicitly.
        $send = $threadType === Thread::TYPE_MESSAGE && Channels::shouldSend($request, $type);

        $mailbox = Mailbox::find($conversation->mailbox_id);

        if ($send && Channels::isDeliverable($type)) {
            if (!$mailbox) {
                return response()->json([
                    'message' => 'Mailbox not found',
                    'status_code' => 404,
                ], 404);
            }

            // Fail fast with a clear error instead of queueing a message that
            // can never be delivered (mailbox without WhatsApp/SMTP config).
            $check = Channels::check($mailbox, $type);
            if (!$check['ok']) {
                return Channels::errorResponse($check['error'], $check['message']);
            }
        }

        try {
            $customer = $conversation->customer;

            // Recipient: the phone for phone / WhatsApp conversations, the
            // main email otherwise.
            $recipient = '';
            if ($customer) {
                $recipient = Channels::requiresPhone($type)
                    ? $customer->getMainPhoneNumber()
                    : $customer->getMainEmail();
            }

            // Thread is guarded, so it must be built through Thread::create().
            $thread = Thread::create(
                $conversation,
                $threadType,
                $request->input('body'),
                [
                    'user_id' => $userId ? (int) $userId : $conversation->user_id,
                    'created_by_user_id' => $userId ? (int) $userId : null,
                    'customer_id' => $customer ? $customer->id : null,
                    'source_via' => Thread::PERSON_USER,
                    'source_type' => Thread::SOURCE_TYPE_API,
                    'from' => $mailbox ? $mailbox->name . ' <' . $mailbox->email . '>' : '',
                    'to' => $recipient,
                    'cc' => Conversation::sanitizeEmails($request->input('cc', [])),
                    'bcc' => Conversation::sanitizeEmails($request->input('bcc', [])),
                ]
            );

            $queued = Channels::queueDelivery($conversation, $thread, $send);

            $this->logAudit($request, 'thread_created', $thread->id, 201);

            $response = ThreadDTO::fromModel($thread)->toArray();
            $response['delivery'] = Channels::deliveryInfo($type, $queued);

            return response()->json($response, 201);
        } catch (\Exception $e) {
            \Log::error('RestApi: error creating thread: ' . $e->getMessage());

            return response()->json([
                'message' => 'Error creating thread: ' . $e->getMessage(),
                'status_code' => 500,
            ], 500);
        }
    }

    /**
     * Update thread
     * PUT /api/v1/threads/:id
     */
    public function update(Request $request, $id): JsonResponse
    {
        $userId = $request->attributes->get('user_id');
        $scope = $request->attributes->get('mailbox_ids');

        $thread = Thread::with('conversation')->find($id);

        if (!$thread) {
            return response()->json([
                'message' => 'Thread not found',
                'status_code' => 404,
            ], 404);
        }

        // Check authorization
        if (!MailboxAccess::allows($scope, optional($thread->conversation)->mailbox_id)) {
            return response()->json([
                'message' => 'Unauthorized to update this thread',
                'status_code' => 403,
            ], 403);
        }

        // Can only edit if user is the creator
        if ((int) $thread->user_id !== (int) $userId) {
            return response()->json([
                'message' => 'You can only edit your own threads',
                'status_code' => 403,
            ], 403);
        }

        try {
            if ($request->has('body')) {
                $thread->body = $request->input('body');
            }

            $thread->save();

            $this->logAudit($request, 'thread_updated', $thread->id);

            return response()->json(ThreadDTO::fromModel($thread)->toArray(), 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error updating thread: ' . $e->getMessage(),
                'status_code' => 500,
            ], 500);
        }
    }

    /**
     * Delete thread
     * DELETE /api/v1/threads/:id
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $scope = $request->attributes->get('mailbox_ids');

        $thread = Thread::with('conversation')->find($id);

        if (!$thread) {
            return response()->json([
                'message' => 'Thread not found',
                'status_code' => 404,
            ], 404);
        }

        // Check authorization
        if (!MailboxAccess::allows($scope, optional($thread->conversation)->mailbox_id)) {
            return response()->json([
                'message' => 'Unauthorized to delete this thread',
                'status_code' => 403,
            ], 403);
        }

        try {
            $thread->delete();

            $this->logAudit($request, 'thread_deleted', $thread->id, 204);

            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error deleting thread: ' . $e->getMessage(),
                'status_code' => 500,
            ], 500);
        }
    }
}
