<?php

namespace Modules\RestApi\Tests\Feature\Api;

use Tests\TestCase;
use App\User;
use App\Mailbox;
use App\Customer;
use Modules\RestApi\Entities\ApiKey;

class ConversationsApiTest extends TestCase
{
    protected $user;
    protected $apiToken;
    protected $mailbox;
    protected $customer;

    public function setUp(): void
    {
        parent::setUp();

        // Create test user
        $this->user = factory(User::class)->create();

        // Create test mailbox
        $this->mailbox = factory(Mailbox::class)->create();

        // Create test customer
        $this->customer = Customer::create('test@example.com', [
            'first_name' => 'Test',
            'last_name' => 'Customer',
        ]);

        // Create API token
        $token = ApiKey::generateToken();
        $this->apiToken = ApiKey::create([
            'user_id' => $this->user->id,
            'name' => 'Test Token',
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'mailbox_ids' => json_encode([$this->mailbox->id]),
            'active' => true,
        ]);

        $this->token = $token;
    }

    public function test_list_conversations_requires_auth()
    {
        $response = $this->getJson('/api/v1/conversations');
        $response->assertStatus(401);
    }

    public function test_list_conversations_with_valid_token()
    {
        $response = $this->getJson('/api/v1/conversations', [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        $response->assertStatus(200);

        $body = $response->json();
        foreach (['data', 'meta', 'links'] as $key) {
            $this->assertArrayHasKey($key, $body);
        }
    }

    public function test_create_conversation()
    {
        $response = $this->postJson('/api/v1/conversations', [
            'mailbox_id' => $this->mailbox->id,
            'subject' => 'Test Conversation',
            'to' => ['customer@example.com'],
            'body' => 'This is a test message',
            'status' => 1,
        ], [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        $response->assertStatus(201);

        $body = $response->json();
        foreach (['id', 'mailbox_id', 'subject', 'status'] as $key) {
            $this->assertArrayHasKey($key, $body);
        }
    }

    public function test_create_conversation_validation()
    {
        $response = $this->postJson('/api/v1/conversations', [
            // Missing required fields
            'subject' => 'Test',
        ], [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        $response->assertStatus(422);
    }

    public function test_get_single_conversation()
    {
        $conversation = factory(\App\Conversation::class)->create([
            'mailbox_id' => $this->mailbox->id,
            'customer_id' => $this->customer->id,
        ]);

        $response = $this->getJson('/api/v1/conversations/' . $conversation->id, [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        $response->assertStatus(200);
        $this->assertSame((int) $conversation->id, (int) $response->json()['id']);
    }

    public function test_update_conversation_status()
    {
        $conversation = factory(\App\Conversation::class)->create([
            'mailbox_id' => $this->mailbox->id,
            'customer_id' => $this->customer->id,
            'status' => 1,
        ]);

        $response = $this->putJson('/api/v1/conversations/' . $conversation->id, [
            'status' => 3,
        ], [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        $response->assertStatus(200);
        $this->assertSame(3, (int) $response->json()['status']);
    }

    public function test_delete_conversation()
    {
        $conversation = factory(\App\Conversation::class)->create([
            'mailbox_id' => $this->mailbox->id,
        ]);

        $response = $this->deleteJson('/api/v1/conversations/' . $conversation->id, [], [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        $response->assertStatus(204);
    }

    public function test_unauthorized_mailbox_access()
    {
        $otherMailbox = factory(Mailbox::class)->create();
        $conversation = factory(\App\Conversation::class)->create([
            'mailbox_id' => $otherMailbox->id,
        ]);

        $response = $this->getJson('/api/v1/conversations/' . $conversation->id, [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        $response->assertStatus(403);
    }

    public function test_rate_limiting()
    {
        // Set low rate limit
        $this->apiToken->update(['rate_limit' => 2]);

        // First request should succeed
        $this->getJson('/api/v1/conversations', [
            'Authorization' => 'Bearer ' . $this->token,
        ])->assertStatus(200);

        // Second request should succeed
        $this->getJson('/api/v1/conversations', [
            'Authorization' => 'Bearer ' . $this->token,
        ])->assertStatus(200);

        // Third request should fail
        $response = $this->getJson('/api/v1/conversations', [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        $response->assertStatus(429);
    }
}
