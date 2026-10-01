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
            'user_id' => User::factory()->create()->id,
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
        $user = User::factory()->create();
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
        $user = User::factory()->create();
        $token = ApiKey::generateToken();

        $apiKey = ApiKey::create([
            'user_id' => $user->id,
            'name' => 'Restricted Token',
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'mailbox_ids' => json_encode([1, 2, 3]),
            'active' => true,
        ]);

        $mailboxIds = json_decode($apiKey->mailbox_ids);
        $this->assertCount(3, $mailboxIds);
        $this->assertContains(1, $mailboxIds);
    }
}
