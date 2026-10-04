<?php

namespace Modules\RestApi\Tests\Unit;

use Tests\TestCase;
use Modules\RestApi\Entities\ApiKey;
use App\User;

class ApiKeyTest extends TestCase
{
    public function test_generate_token()
    {
        $token = ApiKey::generateToken();

        $this->assertStringStartsWith('fs_', $token);
        $this->assertGreaterThan(10, strlen($token));
    }

    public function test_token_hashing()
    {
        $token = ApiKey::generateToken();
        $hash = hash('sha256', $token);

        $apiKey = ApiKey::create([
            'user_id' => factory(User::class)->create()->id,
            'name' => 'Test',
            'token' => $token,
            'token_hash' => $hash,
            'active' => true,
        ]);

        $this->assertEquals($hash, $apiKey->token_hash);
        $this->assertTrue(hash_equals($hash, hash('sha256', $token)));
    }

    public function test_token_expiration()
    {
        $user = factory(User::class)->create();
        $token = ApiKey::generateToken();

        $apiKey = ApiKey::create([
            'user_id' => $user->id,
            'name' => 'Expiring Token',
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->subDay(),
            'active' => true,
        ]);

        $this->assertTrue($apiKey->expires_at->isPast());
    }

    public function test_mailbox_restriction()
    {
        $user = factory(User::class)->create();
        $token = ApiKey::generateToken();

        $apiKey = ApiKey::create([
            'user_id' => $user->id,
            'name' => 'Restricted Token',
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'mailbox_ids' => json_encode([1, 2, 3]),
            'active' => true,
        ]);

        // mailbox_ids is normalized by the model accessor/mutator.
        $this->assertSame([1, 2, 3], $apiKey->mailbox_ids);
        $this->assertFalse($apiKey->hasAccessToAllMailboxes());
        $this->assertTrue($apiKey->canAccessMailbox(2));
        $this->assertFalse($apiKey->canAccessMailbox(9));
    }

    public function test_mailbox_restriction_is_not_double_encoded()
    {
        $user = factory(User::class)->create();
        $token = ApiKey::generateToken();

        $apiKey = ApiKey::create([
            'user_id' => $user->id,
            'name' => 'Double encoded',
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            // The CSV the web form sends, as well as an already encoded array,
            // must both end up stored as a single JSON array.
            'mailbox_ids' => '7,8',
            'active' => true,
        ]);

        $raw = \DB::table('api_keys')->where('id', $apiKey->id)->value('mailbox_ids');

        $this->assertSame('[7,8]', $raw);
        $this->assertSame([7, 8], $apiKey->fresh()->mailbox_ids);
    }

    public function test_conversation_type_round_trip()
    {
        $user = factory(User::class)->create();
        $token = ApiKey::generateToken();

        $apiKey = ApiKey::create([
            'user_id' => $user->id,
            'name' => 'WhatsApp token',
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'conversation_type' => 'whatsapp',
            'active' => true,
        ]);

        $this->assertSame('whatsapp', $apiKey->fresh()->conversation_type);
        $this->assertSame('WhatsApp', $apiKey->getConversationTypeName());
        $this->assertArrayHasKey('whatsapp', ApiKey::getConversationTypes());
    }

    public function test_conversation_type_is_optional()
    {
        $user = factory(User::class)->create();
        $token = ApiKey::generateToken();

        $apiKey = ApiKey::create([
            'user_id' => $user->id,
            'name' => 'Any type',
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'conversation_type' => null,
            'active' => true,
        ]);

        $this->assertNull($apiKey->fresh()->conversation_type);
        $this->assertNull($apiKey->getConversationTypeName());
    }

    public function test_empty_mailbox_restriction_means_all_mailboxes()
    {
        $user = factory(User::class)->create();
        $token = ApiKey::generateToken();

        $apiKey = ApiKey::create([
            'user_id' => $user->id,
            'name' => 'All mailboxes',
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'mailbox_ids' => null,
            'active' => true,
        ]);

        $this->assertNull($apiKey->mailbox_ids);
        $this->assertTrue($apiKey->hasAccessToAllMailboxes());
        $this->assertTrue($apiKey->canAccessMailbox(4));
    }
}
