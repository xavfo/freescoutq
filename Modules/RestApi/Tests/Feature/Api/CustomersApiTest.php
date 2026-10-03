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

        $this->user = factory(User::class)->create();

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
        factory(Customer::class, 5)->create();

        $response = $this->getJson('/api/v1/customers', [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        $response->assertStatus(200);

        $body = $response->json();
        foreach (['data', 'meta'] as $key) {
            $this->assertArrayHasKey($key, $body);
        }
    }

    public function test_search_customers()
    {
        $customer = Customer::create('john@example.com', [
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $response = $this->getJson('/api/v1/customers?search=john', [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        $response->assertStatus(200);
        // Search must work by name and by email (emails live in their own table).
        $this->assertContains((int) $customer->id, array_map('intval', array_column($response->json()['data'], 'id')));

        $response = $this->getJson('/api/v1/customers?search=john@example.com', [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        $response->assertStatus(200);
        $this->assertContains((int) $customer->id, array_map('intval', array_column($response->json()['data'], 'id')));
    }

    public function test_create_customer()
    {
        // Unique per run: the database is not refreshed between runs.
        $email = 'jane+' . uniqid() . '@example.com';

        $response = $this->postJson('/api/v1/customers', [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'emails' => [$email],
            'phone' => '+1234567890',
        ], [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        $response->assertStatus(201);
        $this->assertSame('Jane', $response->json()['first_name']);

        $customer = Customer::getByEmail($email);
        $this->assertNotNull($customer, 'El cliente debería haberse creado con su email.');
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
        $customer = Customer::create('test@example.com', [
            'first_name' => 'Test',
            'last_name' => 'Customer',
        ]);

        $response = $this->getJson('/api/v1/customers/' . $customer->id, [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        $response->assertStatus(200);
        $this->assertSame((int) $customer->id, (int) $response->json()['id']);
        $this->assertArrayHasKey('recent_conversations', $response->json());
    }
}
