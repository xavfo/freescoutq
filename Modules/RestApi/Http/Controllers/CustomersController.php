<?php

namespace Modules\RestApi\Http\Controllers;

use App\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\RestApi\Entities\DTOs\CustomerDTO;
use Modules\RestApi\Http\Requests\StoreCustomerRequest;

class CustomersController extends Controller
{
    /**
     * List customers
     * GET /api/v1/customers
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->attributes->get('user_id');

        $query = Customer::query();

        // Apply search filter
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('emails', 'like', "%{$search}%");
            });
        }

        // Sort
        $sort = $request->input('sort', '-created_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
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
            // Check if customer already exists with this email
            $email = $request->input('emails')[0];
            $existing = Customer::where('emails', 'like', "%{$email}%")->first();

            if ($existing) {
                return response()->json([
                    'message' => 'Customer with this email already exists',
                    'status_code' => 409,
                ], 409);
            }

            $customer = Customer::create([
                'first_name' => $request->input('first_name'),
                'last_name' => $request->input('last_name', ''),
                'emails' => json_encode($request->input('emails')),
                'phone' => $request->input('phone', ''),
            ]);

            $this->logAudit($request, 'created', $customer->id);

            return response()->json(CustomerDTO::fromModel($customer)->toArray(), 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error creating customer: ' . $e->getMessage(),
                'status_code' => 500,
            ], 500);
        }
    }

    private function logAudit(Request $request, $action, $customerId)
    {
        try {
            $apiKeyId = $request->attributes->get('api_key_id');

            \Illuminate\Support\Facades\DB::table('api_audit_logs')
                ->where('api_key_id', $apiKeyId)
                ->latest('id')
                ->first()
                ->update([
                    'response_code' => 200,
                    'response_message' => "Customer {$customerId} {$action}",
                ]);
        } catch (\Exception $e) {
            // Silently fail
        }
    }
}
