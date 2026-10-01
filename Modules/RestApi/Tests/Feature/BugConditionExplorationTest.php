<?php

namespace Modules\RestApi\Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug Condition Exploration Test - Bug 1: Duplicate Route Registration
 * 
 * This test explores the bug condition where API routes are registered twice:
 * once in start.php (in the web middleware group with CSRF) and once in
 * RestApiServiceProvider::boot() via loadRoutesFrom().
 * 
 * **CRITICAL**: This test MUST FAIL on unfixed code - the failure confirms the bug exists.
 * 
 * Validates: Requirements 1.1, 1.2, 1.3
 */
class BugConditionExplorationTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $mailbox;
    protected $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->user = User::factory()->create();
        $this->mailbox = Mailbox::factory()->create();
        
        // Create API key with mailbox access
        $this->apiKey = \DB::table('api_keys')->insertGetId([
            'user_id' => $this->user->id,
            'key' => 'test-api-key-' . uniqid(),
            'mailbox_ids' => json_encode([$this->mailbox->id]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create test conversation
        Conversation::factory()->create([
            'mailbox_id' => $this->mailbox->id,
            'customer_id' => Customer::factory()->create()->id,
        ]);
    }

    /**
     * Property 1: Bug Condition - API routes respond with valid JSON
     * 
     * For any request to api/v1/* with a valid Bearer token,
     * the system SHALL return a response with HTTP status in [200-429],
     * valid JSON body, and non-empty content.
     * 
     * **EXPECTED OUTCOME on unfixed code**: Test FAILS (confirms bug exists)
     * - Response may be empty
     * - Response may have CSRF error
     * - Response may have unexpected status code
     */
    public function test_api_v1_conversations_endpoint_returns_valid_json_with_bearer_token()
    {
        // Get the API key for Bearer token
        $apiKeyRecord = \DB::table('api_keys')->find($this->apiKey);
        $token = $apiKeyRecord->key;

        // Make request to GET /api/v1/conversations with valid Bearer token
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->get('/api/v1/conversations');

        // ASSERTION 1: Response status should be in valid range (not CSRF error, not empty)
        $this->assertIn(
            $response->getStatusCode(),
            [200, 201, 204, 400, 401, 403, 404, 422, 429],
            "Response status {$response->getStatusCode()} is not in expected range. " .
            "Response body: {$response->getContent()}"
        );

        // ASSERTION 2: Response should be valid JSON
        $this->assertJson(
            $response->getContent(),
            "Response is not valid JSON. Response body: {$response->getContent()}"
        );

        // ASSERTION 3: Response should not be empty
        $this->assertNotEmpty(
            $response->getContent(),
            "Response body is empty - indicates bug condition (routes registered in web context with CSRF)"
        );

        // ASSERTION 4: For successful requests, verify JSON structure
        if ($response->getStatusCode() === 200) {
            $data = $response->json();
            $this->assertIsArray($data, "Response should be a JSON array or object");
            $this->assertNotEmpty($data, "Response data should not be empty for successful request");
        }
    }

    /**
     * Verify that routes are registered (check via route:list)
     * 
     * This test verifies that the API routes exist in the routing table.
     * If routes are registered in the web context, they may appear duplicated.
     */
    public function test_api_v1_routes_are_registered()
    {
        // Get all registered routes
        $routes = \Route::getRoutes();
        
        // Find all api/v1 routes
        $apiRoutes = [];
        foreach ($routes as $route) {
            if (strpos($route->uri(), 'api/v1') === 0) {
                $apiRoutes[] = $route->uri();
            }
        }

        // ASSERTION: At least some API routes should be registered
        $this->assertNotEmpty(
            $apiRoutes,
            "No api/v1 routes found in routing table - routes may not be registered"
        );

        // ASSERTION: Conversations endpoint should exist
        $this->assertContains(
            'api/v1/conversations',
            $apiRoutes,
            "api/v1/conversations route not found in routing table"
        );
    }

    /**
     * Verify that API routes do NOT have web middleware (CSRF)
     * 
     * This test checks that the api/v1 routes are NOT in the web middleware group.
     * If they are, the CSRF middleware will interfere with API requests.
     */
    public function test_api_v1_routes_do_not_have_web_middleware()
    {
        $routes = \Route::getRoutes();
        
        foreach ($routes as $route) {
            if (strpos($route->uri(), 'api/v1') === 0) {
                $middleware = $route->middleware();
                
                // ASSERTION: API routes should NOT have 'web' middleware
                $this->assertNotContains(
                    'web',
                    $middleware,
                    "Route {$route->uri()} has 'web' middleware - this causes CSRF interference with API requests"
                );
                
                // ASSERTION: API routes should NOT have 'csrf' middleware
                $this->assertNotContains(
                    'csrf',
                    $middleware,
                    "Route {$route->uri()} has 'csrf' middleware - this blocks API requests without CSRF token"
                );
            }
        }
    }

    /**
     * Verify that API routes have correct middleware
     * 
     * API routes should have api-token and api-rate-limit middleware,
     * but NOT web or csrf middleware.
     */
    public function test_api_v1_routes_have_correct_middleware()
    {
        $routes = \Route::getRoutes();
        $foundApiRoute = false;
        
        foreach ($routes as $route) {
            if ($route->uri() === 'api/v1/conversations' && $route->methods()[0] === 'GET') {
                $foundApiRoute = true;
                $middleware = $route->middleware();
                
                // ASSERTION: Should have api-token middleware
                $this->assertContains(
                    'api-token',
                    $middleware,
                    "Route api/v1/conversations should have 'api-token' middleware"
                );
                
                // ASSERTION: Should have api-rate-limit middleware
                $this->assertContains(
                    'api-rate-limit',
                    $middleware,
                    "Route api/v1/conversations should have 'api-rate-limit' middleware"
                );
                
                break;
            }
        }
        
        $this->assertTrue($foundApiRoute, "Could not find GET api/v1/conversations route");
    }
}
