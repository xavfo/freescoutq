<?php

namespace Modules\RestApi\Http\Controllers;

use App\Customer;
use App\Email;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\RestApi\Entities\DTOs\CustomerDTO;
use Modules\RestApi\Http\Requests\StoreCustomerRequest;
use Modules\RestApi\Support\LogsApiAudit;

class CustomersController extends Controller
{
    use LogsApiAudit;

    /**
     * List customers
     * GET /api/v1/customers
     */
    public function index(Request $request): JsonResponse
    {
        $query = Customer::query();

        // Apply search filter. Customer emails live in the "emails"
        // relation, there is no "emails" column on the customers table.
        //
        // A subquery is used instead of orWhereHas(): FreeScout overrides
        // Illuminate\Database\Query\Builder and its addWhereExistsQuery()
        // references an undefined $operator, so every whereHas()/whereExists()
        // throws "compact(): Undefined variable $operator".
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhereIn('id', Email::query()->select('customer_id')->where('email', 'like', "%{$search}%"));
            });
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
        $data = array_map(fn($c) => CustomerDTO::fromModel($c)->toArray(), $paginated->items());

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
     * Get single customer
     * GET /api/v1/customers/:id
     */
    public function show(Request $request, $id): JsonResponse
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return response()->json([
                'message' => 'Customer not found',
                'status_code' => 404,
            ], 404);
        }

        $dto = CustomerDTO::fromModel($customer)->toArray();

        // Add recent conversations
        $conversations = $customer->conversations()
            ->latest()
            ->limit(5)
            ->get();

        $dto['recent_conversations'] = array_map(fn($conv) => [
            'id' => $conv->id,
            'subject' => $conv->subject,
            'status' => $conv->status,
            'created_at' => $conv->created_at->toIso8601String(),
        ], $conversations->all());

        return response()->json($dto, 200);
    }

    /**
     * Create customer
     * POST /api/v1/customers
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        try {
            $emails = $request->input('emails');

            // Emails are stored in the "emails" table, look the customer up there.
            $existing = Customer::getByEmail($emails[0]);

            if ($existing) {
                return response()->json([
                    'message' => 'Customer with this email already exists',
                    'status_code' => 409,
                ], 409);
            }

            // Customer::create() saves the customer and links every email address.
            $customer = Customer::create($emails[0], [
                'first_name' => $request->input('first_name'),
                'last_name' => $request->input('last_name', ''),
                'emails' => $emails,
                'phone' => $request->input('phone'),
            ]);

            if (!$customer) {
                return response()->json([
                    'message' => 'Unable to create the customer',
                    'status_code' => 422,
                ], 422);
            }

            $this->logAudit($request, 'created', $customer->id, 201);

            return response()->json(CustomerDTO::fromModel($customer)->toArray(), 201);
        } catch (\Exception $e) {
            \Log::error('RestApi: error creating customer: ' . $e->getMessage());

            return response()->json([
                'message' => 'Error creating customer: ' . $e->getMessage(),
                'status_code' => 500,
            ], 500);
        }
    }

    /**
     * Update customer
     * PUT /api/v1/customers/:id
     */
    public function update(Request $request, $id): JsonResponse
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return response()->json([
                'message' => 'Customer not found',
                'status_code' => 404,
            ], 404);
        }

        $request->validate([
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:64',
            'emails' => 'nullable|array',
            'emails.*' => 'email',
        ]);

        try {
            if ($request->has('first_name')) {
                $customer->first_name = $request->input('first_name');
            }

            if ($request->has('last_name')) {
                $customer->last_name = (string) $request->input('last_name');
            }

            // Phone is optional; an empty value clears the stored numbers.
            if ($request->has('phone')) {
                $phone = trim((string) $request->input('phone'));
                $customer->setPhones($phone !== '' ? [$phone] : []);
            }

            // Emails are stored in the "emails" table.
            foreach ((array) $request->input('emails', []) as $email) {
                $email = Email::sanitizeEmail($email);
                if ($email) {
                    $customer->addEmail($email, true);
                }
            }

            $customer->save();

            $this->logAudit($request, 'updated', $customer->id);

            return response()->json(CustomerDTO::fromModel($customer)->toArray(), 200);
        } catch (\Exception $e) {
            \Log::error('RestApi: error updating customer: ' . $e->getMessage());

            return response()->json([
                'message' => 'Error updating customer: ' . $e->getMessage(),
                'status_code' => 500,
            ], 500);
        }
    }

    /**
     * Delete customer
     * DELETE /api/v1/customers/:id
     *
     * A customer with conversations is not deleted (409) to avoid leaving
     * conversations without a customer.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $customer = Customer::find($id);

        if (!$customer) {
            return response()->json([
                'message' => 'Customer not found',
                'status_code' => 404,
            ], 404);
        }

        if ($customer->conversations()->count() > 0) {
            return response()->json([
                'message' => 'Customer has conversations and cannot be deleted',
                'status_code' => 409,
                'error' => 'customer_has_conversations',
            ], 409);
        }

        try {
            // Removes the related rows in the "emails" table too.
            $customer->deleteCustomer();

            $this->logAudit($request, 'deleted', $id, 204);

            return response()->json(null, 204);
        } catch (\Exception $e) {
            \Log::error('RestApi: error deleting customer: ' . $e->getMessage());

            return response()->json([
                'message' => 'Error deleting customer: ' . $e->getMessage(),
                'status_code' => 500,
            ], 500);
        }
    }
}
