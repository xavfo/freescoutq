<?php

namespace Modules\RestApi\Tests\Feature\Api;

use App\Mailbox;
use App\User;
use Modules\RestApi\Entities\ApiKey;
use Modules\RestApi\Support\Kanban;
use Tests\TestCase;

class MailboxesKanbanTest extends TestCase
{
    protected $user;
    protected $mailbox;
    protected $token;

    public function setUp(): void
    {
        parent::setUp();

        $this->user = factory(User::class)->create();
        $this->mailbox = factory(Mailbox::class)->create();

        $token = ApiKey::generateToken();
        ApiKey::create([
            'user_id' => $this->user->id,
            'name' => 'Kanban token',
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'mailbox_ids' => json_encode([$this->mailbox->id]),
            'active' => true,
        ]);

        $this->token = $token;
    }

    public function test_kanban_stages_endpoint_returns_the_virtual_none_column_first()
    {
        if (!Kanban::isAvailable()) {
            $this->markTestSkipped('Kanban module is not active in this test database.');
        }

        $response = $this->getJson(
            '/api/v1/mailboxes/' . $this->mailbox->id . '/kanban-stages',
            ['Authorization' => 'Bearer ' . $this->token]
        );

        $response->assertStatus(200);
        $this->assertSame(Kanban::NONE, $response->json()['data'][0]['id']);
        $this->assertSame($this->mailbox->id, $response->json()['meta']['mailbox_id']);
    }

    public function test_kanban_stages_endpoint_respects_mailbox_scope()
    {
        if (!Kanban::isAvailable()) {
            $this->markTestSkipped('Kanban module is not active in this test database.');
        }

        $otherMailbox = factory(Mailbox::class)->create();

        $response = $this->getJson(
            '/api/v1/mailboxes/' . $otherMailbox->id . '/kanban-stages',
            ['Authorization' => 'Bearer ' . $this->token]
        );

        $response->assertStatus(403);
    }
}
