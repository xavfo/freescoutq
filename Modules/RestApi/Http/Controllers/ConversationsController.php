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
use Modules\RestApi\Support\Channels;
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
     *
     * Handles every communication medium: email (1), phone (2), chat (3),
     * custom (4) and WhatsApp (5). The readable form can be sent in "channel"
     * ("email", "whatsapp", ...) instead of the numeric "type".
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

        // Communication medium: "channel" (name) overrides "type" (number).
        $type = Channels::type($request->input('type', Conversation::TYPE_EMAIL));
        if ($request->filled('channel')) {
            $type = Channels::type($request->input('channel'));
        }
        if ($type === null) {
            return Channels::errorResponse(
                Channels::ERROR_UNSUPPORTED_CHANNEL,
                __('Unsupported conversation channel'),
                'channel'
            );
        }

        // array_values() because sanitizeEmails() may remove invalid entries
        // without reindexing the list.
        $to = array_values(Conversation::sanitizeEmails($request->input('to', [])));
        $cc = array_values(Conversation::sanitizeEmails($request->input('cc', [])));
        $bcc = array_values(Conversation::sanitizeEmails($request->input('bcc', [])));

        if (Channels::requiresEmail($type) && empty($to)) {
            return response()->json([
                'message' => 'At least one valid recipient email is required',
                'status_code' => 422,
            ], 422);
        }

        // Phone / WhatsApp conversations are identified by a phone number,
        // which may be sent in "phone" or as the first "to" entry.
        $phone = null;
        if (Channels::requiresPhone($type)) {
            $phone = trim((string) $request->input('phone', ''));
            if ($phone === '') {
                $phone = trim((string) (reset($to) ?: ''));
            }
            if ($phone === '' && !$request->filled('customer_id')) {
                return Channels::errorResponse(
                    Channels::ERROR_PHONE_REQUIRED,
                    __('A phone number is required for WhatsApp/phone conversations'),
                    'phone'
                );
            }
        }

        // Deliver the message to the customer?
        // WhatsApp conversations are delivered by default (that is their
        // purpose). Email conversations are only delivered when the caller
        // asks for it with send_message=true, preserving the historical
        // "create only" behaviour of the API.
        $send = Channels::shouldSend($request, $type);

        if ($send && Channels::isDeliverable($type)) {
            // Fail fast with a clear error instead of queueing a message that
            // can never be delivered (mailbox without WhatsApp/SMTP config).
            $check = Channels::check($mailbox, $type);
            if (!$check['ok']) {
                return Channels::errorResponse($check['error'], $check['message']);
            }
        }

        try {
            $customer = $this->resolveCustomer($request, $type, $to, $phone);

            if (!$customer) {
                return Channels::errorResponse(
                    Channels::ERROR_CUSTOMER_UNRESOLVED,
                    __('Unable to resolve or create the customer'),
                    'to'
                );
            }

            $customerEmail = $this->customerEmailFor($customer, $type, $to);

            // Assignee: optional "assigned_to", token owner by default.
            $assigneeId = $request->filled('assigned_to')
                ? (int) $request->input('assigned_to')
                : ($userId ? (int) $userId : null);

            $conversation = new Conversation();
            $conversation->mailbox_id = $mailbox->id;
            $conversation->customer_id = $customer->id;
            $conversation->customer_email = $customerEmail;
            $conversation->type = $type;
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
                'to' => Channels::requiresPhone($type) ? $phone : $to,
                'cc' => $cc,
                'bcc' => $bcc,
            ]);

            $thread->first = true;
            $thread->save();

            $queued = Channels::queueDelivery($conversation, $thread, $send);

            $this->logAudit($request, 'created', $conversation->id, 201);

            // Reload so the response exposes the values written by ThreadObserver
            // (threads_count, preview, last_reply_at).
            $conversation = Conversation::find($conversation->id);

            $response = ConversationDTO::fromModel($conversation)->toArray();
            $response['delivery'] = Channels::deliveryInfo($type, $queued);

            return response()->json($response, 201);
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

    /**
     * Resolve an existing customer or create a new one.
     *
     * @param  Request    $request
     * @param  int|string $type
     * @param  array      $to
     * @param  string|null $phone
     * @return Customer|null
     */
    protected function resolveCustomer(Request $request, $type, array $to, $phone)
    {
        if ($request->filled('customer_id')) {
            $customer = Customer::find($request->input('customer_id'));
            if ($customer) {
                return $customer;
            }
        }

        $email = Channels::requiresEmail($type) ? ($to[0] ?? null) : null;

        if ($email && ($customer = Customer::getByEmail($email))) {
            return $customer;
        }

        if ($phone && ($customer = Customer::findByPhone($phone))) {
            return $customer;
        }

        // Build the data set for a new customer from name / phone.
        $data = [];
        $name = trim((string) $request->input('name', ''));
        if ($name !== '') {
            $parts = explode(' ', $name);
            $data['first_name'] = $parts[0];
            if (!empty($parts[1])) {
                $data['last_name'] = $parts[1];
            }
        }
        if ($phone) {
            $data['phones'] = [$phone];
        }

        if ($email) {
            // FreeScout keeps customer emails in the "emails" table,
            // Customer::create() links the address to the customer.
            return Customer::create($email, $data);
        }

        if ($data) {
            return Customer::createWithoutEmail($data);
        }

        return null;
    }

    /**
     * Email stored on the conversation.
     *
     * Email conversations use the recipient address; phone / WhatsApp use the
     * customer's main email when it exists (it may be empty).
     *
     * @param  Customer   $customer
     * @param  int|string $type
     * @param  array      $to
     * @return string
     */
    protected function customerEmailFor(Customer $customer, $type, array $to)
    {
        if (Channels::requiresEmail($type)) {
            return isset($to[0]) ? $to[0] : '';
        }

        return (string) $customer->getMainEmail();
    }
}
