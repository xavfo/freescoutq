<?php

namespace Modules\RestApi\Tests\Feature\Api;

use Tests\TestCase;
use App\Customer;
use App\User;
use Modules\RestApi\Entities\ApiKey;

class CustomersApiTest extends TestCase
{
    protected $user;
    protected $token;

    public function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $token = ApiKey::generateToken();
        ApiKey::create([
            'user_id' => $this->user->id,
            'name' => 'Test Token',
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'active' => true,
        ]);

        $this->token = $token;
    }

    public function test_list_customers()
    {
        Customer::factory()->count(5)->create();

        $response = $this->getJson('/api/v1/customers', [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'meta']);
    }

    public function test_search_customers()
    {
        Customer::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'emails' => json_encode(['john@example.com']),
        ]);

        $response = $this->getJson('/api/v1/customers?search=john', [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_create_customer()
    {
        $response = $this->postJson('/api/v1/customers', [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'emails' => ['jane@example.com'],
            'phone' => '+1234567890',
        ], [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        $response->assertStatus(201);
        $response->assertJson(['first_name' => 'Jane']);
    }

    public function test_create_customer_validation()
    {
        $response = $this->postJson('/api/v1/customers', [
            // Missing required fields
        ], [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        $response->assertStatus(422);
    }

    public function test_get_customer()
    {
        $customer = Customer::create([
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'emails' => json_encode(['test@example.com']),
        ]);

        $response = $this->getJson('/api/v1/customers/' . $customer->id, [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['id' => $customer->id]);
        $response->assertJsonStructure(['recent_conversations']);
    }
}
