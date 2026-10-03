<?php

namespace Modules\RestApi\Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\User;
use Modules\RestApi\Entities\ApiKey;
use Tests\TestCase;

/**
 * Regression test for Bug 1: the API routes were registered twice.
 *
 * FreeScout includes every module's start.php inside the "web" route group,
 * so requiring Http/routes.php from start.php registered the api/v1 routes a
 * second time wrapped by the CSRF middleware and made the API unusable.
 *
 * After the fix the routes must be registered exactly once, with the
 * api-token and api-rate-limit middlewares only.
 *
 * Following the project convention the tests run against an already migrated
 * database (see test.yml: "artisan migrate" before "./vendor/bin/phpunit").
 * The module must be active in the "modules" table so its service provider
 * (routes + migrations) is loaded.
 */
class BugConditionExplorationTest extends TestCase
{
    protected $user;
    protected $mailbox;
    protected $apiKey;
    protected $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = factory(User::class)->create();
        $this->mailbox = factory(Mailbox::class)->create();

        $this->token = ApiKey::generateToken();

        $this->apiKey = ApiKey::create([
            'user_id' => $this->user->id,
            'name' => 'Test token',
            'token' => $this->token,
            'token_hash' => hash('sha256', $this->token),
            'mailbox_ids' => [$this->mailbox->id],
            'rate_limit' => 1000,
            'active' => true,
        ]);

        factory(Conversation::class)->create([
            'mailbox_id' => $this->mailbox->id,
            'customer_id' => factory(Customer::class)->create()->id,
            'created_by_user_id' => $this->user->id,
        ]);
    }

    /**
     * Property 1: an api/v1 request with a valid Bearer token returns a
     * non-empty JSON response with a valid status code and no CSRF error.
     */
    public function test_api_v1_conversations_endpoint_returns_valid_json_with_bearer_token()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])->get('/api/v1/conversations');

        $this->assertContains(
            $response->getStatusCode(),
            [200, 201, 204, 400, 401, 403, 404, 422, 429],
            'Unexpected status ' . $response->getStatusCode() . ': ' . $response->getContent()
        );

        $this->assertNotEmpty($response->getContent());
        $this->assertJson($response->getContent());

        $response->assertStatus(200);

        $body = $response->json();
        foreach (['data', 'meta', 'links'] as $key) {
            $this->assertArrayHasKey($key, $body);
        }
    }

    /**
     * Each API route must be registered exactly once.
     */
    public function test_api_v1_routes_are_registered_exactly_once()
    {
        $counts = [];

        foreach (\Route::getRoutes() as $route) {
            if (strpos($route->uri(), 'api/v1') !== 0) {
                continue;
            }

            $key = implode('|', array_diff($route->methods(), ['HEAD'])) . ' ' . $route->uri();

            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $this->assertNotEmpty($counts, 'No api/v1 routes are registered.');

        $duplicates = array_filter($counts, function ($count) {
            return $count > 1;
        });

        $this->assertSame([], $duplicates, 'Duplicated API routes: ' . implode(', ', array_keys($duplicates)));

        $this->assertArrayHasKey('GET api/v1/conversations', $counts);
    }

    /**
     * API routes must not be wrapped by the web (CSRF) middleware group.
     */
    public function test_api_v1_routes_do_not_have_web_middleware()
    {
        foreach (\Route::getRoutes() as $route) {
            if (strpos($route->uri(), 'api/v1') !== 0) {
                continue;
            }

            $middleware = $route->middleware();

            $this->assertNotContains('web', $middleware, $route->uri() . ' has the web middleware.');
            $this->assertNotContains('csrf', $middleware, $route->uri() . ' has the csrf middleware.');
        }
    }

    /**
     * API routes must carry the API middlewares.
     */
    public function test_api_v1_routes_have_correct_middleware()
    {
        foreach (\Route::getRoutes() as $route) {
            if (strpos($route->uri(), 'api/v1') !== 0) {
                continue;
            }

            $middleware = $route->middleware();

            $this->assertContains('api-token', $middleware, $route->uri() . ' is missing api-token.');
            $this->assertContains('api-rate-limit', $middleware, $route->uri() . ' is missing api-rate-limit.');
        }
    }

    /**
     * The token management screen must stay behind the web stack and
     * requires an administrator.
     */
    public function test_token_management_routes_are_protected()
    {
        $found = 0;

        foreach (\Route::getRoutes() as $route) {
            if (strpos($route->uri(), 'restapi/tokens') !== 0) {
                continue;
            }

            $found++;

            $middleware = $route->middleware();

            $this->assertContains('web', $middleware);
            $this->assertContains('auth', $middleware);
            $this->assertContains('roles', $middleware);
        }

        $this->assertGreaterThan(0, $found, 'No restapi/tokens routes are registered.');
    }
}
