<?php

namespace Modules\RestApi\Tests\Feature\Api;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression tests for Bug 3: write endpoints returned HTTP 500 because the
 * raw "mailbox_ids" value was passed to in_array().
 *
 * Tokens created through the web form stored the ids double encoded, so
 * json_decode() returned a string and in_array() raised
 * "Argument #2 ($haystack) must be of type array, string given" on
 * GET /conversations/{id}, POST /conversations and
 * POST /conversations/{id}/threads, while GET /conversations silently
 * ignored the restriction.
 */
class MailboxRestrictionRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $mailbox;
    protected $otherMailbox;
    protected $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = factory(User::class)->create();
        $this->mailbox = factory(Mailbox::class)->create();
        $this->otherMailbox = factory(Mailbox::class)->create();

        $this->customer = Customer::create('restapi-customer@example.com');
    }

    /**
     * Insert a token with the given raw mailbox_ids value, bypassing the
     * model so legacy (badly encoded) rows can be reproduced.
     *
     * @param  mixed $rawMailboxIds
     * @return string The plain token.
     */
    protected function createRawToken($rawMailboxIds)
    {
        $token = 'fs_' . bin2hex(random_bytes(32));

        DB::table('api_keys')->insert([
            'user_id' => $this->user->id,
            'name' => 'Regression token',
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'mailbox_ids' => $rawMailboxIds,
            'rate_limit' => 1000,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    protected function headers($token)
    {
        return [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ];
    }

    /**
     * Native JSON columns reject text that is not valid JSON, so the raw CSV
     * shape can only be stored when the column is text (MySQL < 5.7 without the
     * JSON type, MariaDB, or a manually altered column). Instead of asking the
     * schema (which needs doctrine/dbal) the value is simply stored and the
     * test is skipped when the column rejects it.
     *
     * @param  string $rawMailboxIds
     * @return string
     */
    protected function createRawTokenOrSkip($rawMailboxIds)
    {
        try {
            return $this->createRawToken($rawMailboxIds);
        } catch (\Exception $e) {
            $this->markTestSkipped(
                'api_keys.mailbox_ids rechaza valores que no son JSON: ' . $e->getMessage()
            );
        }
    }

    protected function conversationIn(Mailbox $mailbox)
    {
        return factory(Conversation::class)->create([
            'mailbox_id' => $mailbox->id,
            'customer_id' => $this->customer->id,
            'created_by_user_id' => $this->user->id,
        ]);
    }

    public function test_show_works_with_a_csv_encoded_token()
    {
        $token = $this->createRawTokenOrSkip($this->mailbox->id . ',' . $this->otherMailbox->id);
        $conversation = $this->conversationIn($this->mailbox);

        $response = $this->getJson('/api/v1/conversations/' . $conversation->id, $this->headers($token));

        $response->assertStatus(200);
        $this->assertSame((int) $conversation->id, (int) $response->json()['id']);
    }

    public function test_show_works_with_a_csv_stringified_token()
    {
        // Same CSV, stored as a JSON string (the shape produced when the CSV
        // was assigned to the json cast attribute).
        $token = $this->createRawToken(json_encode($this->mailbox->id . ',' . $this->otherMailbox->id));
        $conversation = $this->conversationIn($this->mailbox);

        $response = $this->getJson('/api/v1/conversations/' . $conversation->id, $this->headers($token));

        $response->assertStatus(200);
        $this->assertSame((int) $conversation->id, (int) $response->json()['id']);
    }

    public function test_show_works_with_a_double_encoded_token()
    {
        // This is the value produced by the old writer: json_encode() on the
        // controller plus the json cast on the model.
        $token = $this->createRawToken(json_encode(json_encode([$this->mailbox->id])));
        $conversation = $this->conversationIn($this->mailbox);

        $response = $this->getJson('/api/v1/conversations/' . $conversation->id, $this->headers($token));

        $response->assertStatus(200);
        $this->assertSame((int) $conversation->id, (int) $response->json()['id']);
    }

    public function test_index_does_not_leak_other_mailboxes()
    {
        $token = $this->createRawToken(json_encode(json_encode([$this->mailbox->id])));

        $mine = $this->conversationIn($this->mailbox);
        $theirs = $this->conversationIn($this->otherMailbox);

        $response = $this->getJson('/api/v1/conversations', $this->headers($token));

        $response->assertStatus(200);

        $ids = array_column($response->json()['data'], 'id');

        $this->assertContains((int) $mine->id, array_map('intval', $ids));
        $this->assertNotContains((int) $theirs->id, array_map('intval', $ids));
    }

    public function test_token_without_mailbox_ids_can_access_every_mailbox()
    {
        $token = $this->createRawToken(null);

        $conversation = $this->conversationIn($this->otherMailbox);

        $response = $this->getJson('/api/v1/conversations/' . $conversation->id, $this->headers($token));

        $response->assertStatus(200);
        $this->assertSame((int) $conversation->id, (int) $response->json()['id']);
    }

    public function test_show_is_forbidden_for_a_mailbox_out_of_scope()
    {
        $token = $this->createRawToken(json_encode(json_encode([$this->mailbox->id])));
        $conversation = $this->conversationIn($this->otherMailbox);

        $this->getJson('/api/v1/conversations/' . $conversation->id, $this->headers($token))
            ->assertStatus(403);
    }

    public function test_index_returns_an_empty_list_for_an_unparsable_token()
    {
        $token = $this->createRawTokenOrSkip('garbage');
        $this->conversationIn($this->mailbox);

        $response = $this->getJson('/api/v1/conversations', $this->headers($token));

        $response->assertStatus(200);
        $this->assertSame([], $response->json()['data']);
    }

    public function test_create_conversation_with_a_restricted_token()
    {
        $token = $this->createRawToken(json_encode([$this->mailbox->id, $this->otherMailbox->id]));

        $response = $this->postJson('/api/v1/conversations', [
            'mailbox_id' => $this->mailbox->id,
            'subject' => 'Regression test',
            'to' => ['new-customer@example.com'],
            'body' => 'Body of the regression test',
            'status' => 1,
        ], $this->headers($token));

        $response->assertStatus(201);

        $body = $response->json();
        foreach (['id', 'mailbox_id', 'subject', 'status'] as $key) {
            $this->assertArrayHasKey($key, $body);
        }

        $conversation = Conversation::find($body['id']);

        $this->assertNotNull($conversation);
        $this->assertSame((int) $this->mailbox->id, (int) $conversation->mailbox_id);
        $this->assertSame(1, (int) $conversation->threads_count);
        $this->assertSame(Conversation::SOURCE_TYPE_API, (int) $conversation->source_type);
        $this->assertSame(Conversation::PERSON_USER, (int) $conversation->source_via);
        $this->assertNotEmpty($conversation->folder_id);
    }

    public function test_create_conversation_is_forbidden_in_another_mailbox()
    {
        $token = $this->createRawToken(json_encode(json_encode([$this->mailbox->id])));

        $this->postJson('/api/v1/conversations', [
            'mailbox_id' => $this->otherMailbox->id,
            'subject' => 'Not allowed',
            'to' => ['new-customer@example.com'],
            'body' => 'Body',
        ], $this->headers($token))->assertStatus(403);
    }

    public function test_create_thread_with_a_double_encoded_token()
    {
        $token = $this->createRawToken(json_encode(json_encode([$this->mailbox->id])));
        $conversation = $this->conversationIn($this->mailbox);

        $response = $this->postJson('/api/v1/conversations/' . $conversation->id . '/threads', [
            'body' => 'A new reply',
        ], $this->headers($token));

        $response->assertStatus(201);

        $body = $response->json();
        foreach (['id', 'conversation_id', 'type', 'body'] as $key) {
            $this->assertArrayHasKey($key, $body);
        }

        $this->assertSame((int) $conversation->id, (int) $body['conversation_id']);
    }

    public function test_create_thread_is_forbidden_out_of_scope()
    {
        $token = $this->createRawToken(json_encode(json_encode([$this->mailbox->id])));
        $conversation = $this->conversationIn($this->otherMailbox);

        $this->postJson('/api/v1/conversations/' . $conversation->id . '/threads', [
            'body' => 'A new reply',
        ], $this->headers($token))->assertStatus(403);
    }

    public function test_a_token_whose_hash_does_not_match_is_rejected()
    {
        // The stored hash never matches the presented token.
        DB::table('api_keys')->insert([
            'user_id' => $this->user->id,
            'name' => 'Unrelated token',
            // Unique per run: "token" has a unique index and the database
            // is not refreshed between runs.
            'token' => 'fs_' . bin2hex(random_bytes(16)),
            'token_hash' => hash('sha256', 'a-different-secret-' . uniqid()),
            'mailbox_ids' => null,
            'rate_limit' => 1000,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/conversations', $this->headers('fs_any'))
            ->assertStatus(401);
    }
}
