<?php

namespace Modules\RestApi\Http\Controllers;

use App\Conversation;
use App\Mailbox;
use App\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\RestApi\Entities\DTOs\ConversationDTO;
use Modules\RestApi\Http\Requests\StoreConversationRequest;
use Modules\RestApi\Http\Requests\UpdateConversationRequest;

class ConversationsController extends Controller
{
    /**
     * List conversations
     * GET /api/v1/conversations
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->attributes->get('user_id');
        $mailboxIds = $request->attributes->get('mailbox_ids');

        $query = Conversation::query();

        // Authorization: user can only see their mailbox conversations
        // If mailbox_ids is null, user has access to all mailboxes
        // If mailbox_ids is an array, filter by those mailboxes
        if ($mailboxIds !== null && is_array($mailboxIds) && count($mailboxIds) > 0) {
            $query->whereIn('mailbox_id', $mailboxIds);
        } elseif ($mailboxIds !== null && is_array($mailboxIds) && count($mailboxIds) === 0) {
            // Empty array means no mailboxes assigned, return empty
            return response()->json([
                'data' => [],
                'meta' => [
                    'total' => 0,
                    'page' => 1,
                    'per_page' => 20,
                ],
            ], 200);
        }
        // If mailbox_ids is null, no filtering is applied (user has access to all)

        // Apply filters
        if ($request->has('status')) {
            $statuses = explode(',', $request->input('status'));
            $query->whereIn('status', $statuses);
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
        $data = array_map(function($conv) {
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
        $userId = $request->attributes->get('user_id');
        $mailboxIds = $request->attributes->get('mailbox_ids');

        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json([
                'message' => 'Conversation not found',
                'status_code' => 404,
            ], 404);
        }

        // Check authorization
        if (!in_array($conversation->mailbox_id, $mailboxIds ?? [])) {
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
        $mailboxIds = $request->attributes->get('mailbox_ids');

        // Validate mailbox authorization
        if (!in_array($request->input('mailbox_id'), $mailboxIds ?? [])) {
            return response()->json([
                'message' => 'Unauthorized to create conversation in this mailbox',
                'status_code' => 403,
            ], 403);
        }

        $mailbox = Mailbox::find($request->input('mailbox_id'));
        if (!$mailbox) {
            return response()->json([
                'message' => 'Mailbox not found',
                'status_code' => 404,
            ], 404);
        }

        try {
            // Create or get customer
            $customer = null;
            if ($request->has('customer_id')) {
                $customer = \App\Customer::find($request->input('customer_id'));
            } else {
                // Auto-create customer from email
                $email = $request->input('to')[0];
                $customer = \App\Customer::where('emails', 'like', "%{$email}%")->first();

                if (!$customer) {
                    $customer = \App\Customer::create([
                        'first_name' => $email,
                        'emails' => json_encode([$email]),
                    ]);
                }
            }

            // Create conversation
            $conversation = Conversation::create([
                'mailbox_id' => $request->input('mailbox_id'),
                'customer_id' => $customer->id,
                'user_id' => $userId,
                'subject' => $request->input('subject'),
                'status' => $request->input('status', 1),
                'type' => $request->input('type', 1), // Email by default
                'state' => 2, // Published
            ]);

            // Create initial thread
            $thread = \App\Thread::create([
                'conversation_id' => $conversation->id,
                'user_id' => $userId,
                'type' => 2, // Message
                'body' => $request->input('body'),
                'from' => $mailbox->from_name . ' <' . $mailbox->from_email . '>',
                'to' => implode(',', $request->input('to')),
                'cc' => implode(',', $request->input('cc', [])),
                'bcc' => implode(',', $request->input('bcc', [])),
            ]);

            // Log audit
            $this->logAudit($request, 'created', $conversation->id);

            return response()->json(
                ConversationDTO::fromModel($conversation)->toArray(),
                201
            );
        } catch (\Exception $e) {
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
        $userId = $request->attributes->get('user_id');
        $mailboxIds = $request->attributes->get('mailbox_ids');

        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json([
                'message' => 'Conversation not found',
                'status_code' => 404,
            ], 404);
        }

        // Check authorization
        if (!in_array($conversation->mailbox_id, $mailboxIds ?? [])) {
            return response()->json([
                'message' => 'Unauthorized to update this conversation',
                'status_code' => 403,
            ], 403);
        }

        try {
            if ($request->has('status')) {
                $conversation->status = $request->input('status');
            }

            if ($request->has('assigned_to')) {
                $conversation->user_id = $request->input('assigned_to');
            }

            if ($request->has('customer_id')) {
                $conversation->customer_id = $request->input('customer_id');
            }

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
        $userId = $request->attributes->get('user_id');
        $mailboxIds = $request->attributes->get('mailbox_ids');

        $conversation = Conversation::find($id);

        if (!$conversation) {
            return response()->json([
                'message' => 'Conversation not found',
                'status_code' => 404,
            ], 404);
        }

        // Check authorization
        if (!in_array($conversation->mailbox_id, $mailboxIds ?? [])) {
            return response()->json([
                'message' => 'Unauthorized to delete this conversation',
                'status_code' => 403,
            ], 403);
        }

        try {
            $conversation->state = 3; // Deleted state
            $conversation->save();

            $this->logAudit($request, 'deleted', $conversation->id);

            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error deleting conversation: ' . $e->getMessage(),
                'status_code' => 500,
            ], 500);
        }
    }

    private function logAudit(Request $request, $action, $conversationId)
    {
        try {
            $apiKeyId = $request->attributes->get('api_key_id');
            $userId = $request->attributes->get('user_id');

            \Illuminate\Support\Facades\DB::table('api_audit_logs')
                ->where('api_key_id', $apiKeyId)
                ->latest('id')
                ->first()
                ->update([
                    'response_code' => 200,
                    'response_message' => "Conversation {$conversationId} {$action}",
                ]);
        } catch (\Exception $e) {
            // Silently fail
        }
    }
}
