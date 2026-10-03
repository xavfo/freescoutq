<?php

namespace Modules\RestApi\Tests\Feature\Api;

use Tests\TestCase;
use App\User;
use App\Mailbox;
use App\Conversation;
use App\Thread;
use Modules\RestApi\Entities\ApiKey;

class ThreadsApiTest extends TestCase
{
    protected $user;
    protected $apiToken;
    protected $mailbox;
    protected $conversation;
    protected $token;

    public function setUp(): void
    {
        parent::setUp();

        $this->user = factory(User::class)->create();
        $this->mailbox = factory(Mailbox::class)->create();
        $this->conversation = factory(Conversation::class)->create([
            'mailbox_id' => $this->mailbox->id,
        ]);

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

    public function test_list_threads()
    {
        factory(Thread::class, 3)->create([
            'conversation_id' => $this->conversation->id,
        ]);

        $response = $this->getJson(
            '/api/v1/conversations/' . $this->conversation->id . '/threads',
            ['Authorization' => 'Bearer ' . $this->token]
        );

        $response->assertStatus(200);
        $this->assertCount(3, $response->json()['data']);
    }

    public function test_create_thread()
    {
        $response = $this->postJson(
            '/api/v1/conversations/' . $this->conversation->id . '/threads',
            [
                'type' => 2,
                'body' => 'This is a reply',
            ],
            ['Authorization' => 'Bearer ' . $this->token]
        );

        $response->assertStatus(201);
        $this->assertSame('This is a reply', $response->json()['body']);
    }

    public function test_create_thread_validation()
    {
        $response = $this->postJson(
            '/api/v1/conversations/' . $this->conversation->id . '/threads',
            [
                // Missing body
                'type' => 2,
            ],
            ['Authorization' => 'Bearer ' . $this->token]
        );

        $response->assertStatus(422);
    }

    public function test_update_thread()
    {
        $thread = factory(Thread::class)->create([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'body' => 'Original content',
        ]);

        $response = $this->putJson(
            '/api/v1/threads/' . $thread->id,
            ['body' => 'Updated content'],
            ['Authorization' => 'Bearer ' . $this->token]
        );

        $response->assertStatus(200);
        $this->assertSame('Updated content', $response->json()['body']);
    }

    public function test_delete_thread()
    {
        $thread = factory(Thread::class)->create([
            'conversation_id' => $this->conversation->id,
        ]);

        $response = $this->deleteJson(
            '/api/v1/threads/' . $thread->id,
            [],
            ['Authorization' => 'Bearer ' . $this->token]
        );

        $response->assertStatus(204);
    }
}
