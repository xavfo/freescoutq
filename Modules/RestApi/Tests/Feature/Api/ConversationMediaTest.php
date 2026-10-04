<?php

namespace Modules\RestApi\Tests\Feature\Api;

use Tests\TestCase;
use App\User;
use App\Mailbox;
use App\Conversation;
use App\Customer;
use App\Jobs\SendReplyToCustomer;
use App\Jobs\SendWhatsappReply;
use Illuminate\Support\Facades\Queue;
use Modules\RestApi\Entities\ApiKey;
use Modules\RestApi\Support\Channels;

/**
 * Multi-medium behaviour of the REST API endpoints.
 *
 * Covers:
 *  - email / phone / WhatsApp payloads,
 *  - real delivery enqueueing (email and Evolution API),
 *  - structured errors when the mailbox is not configured for the channel.
 */
class ConversationMediaTest extends TestCase
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
        // A token with no conversation_type: tokens are not restricted by
        // communication medium anymore.
        ApiKey::create([
            'user_id' => $this->user->id,
            'name' => 'Media token',
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'mailbox_ids' => json_encode([$this->mailbox->id]),
            'active' => true,
        ]);

        $this->token = $token;
    }

    protected function headers()
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    /**
     * Configure the mailbox so it can deliver WhatsApp messages.
     */
    protected function configureWhatsapp()
    {
        $this->mailbox->setMetaParam('whatsapp', [
            'enabled'  => true,
            'url'      => 'https://evolution.test',
            'instance' => 'test-instance',
            'apikey'   => 'plain-test-key',
        ]);
        $this->mailbox->save();
    }

    public function test_email_conversation_defaults_to_create_only()
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/conversations', [
            'mailbox_id' => $this->mailbox->id,
            'subject' => 'Email conversation',
            'to' => ['customer@example.com'],
            'body' => 'Hello by email',
        ], $this->headers());

        $response->assertStatus(201);

        $body = $response->json();
        $this->assertSame('email', $body['channel']);
        $this->assertSame(Conversation::TYPE_EMAIL, $body['type']);
        $this->assertFalse($body['delivery']['send_message']);

        // No email is sent unless explicitly requested.
        Queue::assertNotPushed(SendReplyToCustomer::class);
    }

    public function test_email_send_reports_missing_configuration()
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/conversations', [
            'mailbox_id' => $this->mailbox->id,
            'subject' => 'Email conversation',
            'to' => ['customer@example.com'],
            'body' => 'Hello by email',
            'send_message' => true,
        ], $this->headers());

        $response->assertStatus(422);
        $this->assertSame('email_not_configured', $response->json()['error']);
        Queue::assertNotPushed(SendReplyToCustomer::class);
    }

    public function test_email_send_is_queued_when_configured()
    {
        Queue::fake();

        // PHP mail is always "active", no SMTP server needed.
        $this->mailbox->out_method = Mailbox::OUT_METHOD_PHP_MAIL;
        $this->mailbox->save();

        $response = $this->postJson('/api/v1/conversations', [
            'mailbox_id' => $this->mailbox->id,
            'subject' => 'Email conversation',
            'to' => ['customer@example.com'],
            'body' => 'Hello by email',
            'send_message' => true,
        ], $this->headers());

        $response->assertStatus(201);
        $this->assertTrue($response->json()['delivery']['send_message']);

        Queue::assertPushed(SendReplyToCustomer::class);
    }

    public function test_whatsapp_conversation_requires_configuration()
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/conversations', [
            'mailbox_id' => $this->mailbox->id,
            'subject' => 'WhatsApp conversation',
            'type' => Conversation::TYPE_WHATSAPP,
            'phone' => '+34 600 11 22 33',
            'name' => 'Jane Doe',
            'body' => 'Hello by WhatsApp',
        ], $this->headers());

        $response->assertStatus(422);
        $this->assertSame('whatsapp_not_configured', $response->json()['error']);
        Queue::assertNotPushed(SendWhatsappReply::class);
    }

    public function test_whatsapp_conversation_is_queued_when_configured()
    {
        Queue::fake();
        $this->configureWhatsapp();

        $response = $this->postJson('/api/v1/conversations', [
            'mailbox_id' => $this->mailbox->id,
            'subject' => 'WhatsApp conversation',
            'channel' => 'whatsapp', // readable form instead of type=5
            'phone' => '+34 600 11 22 33',
            'name' => 'Jane Doe',
            'body' => 'Hello by WhatsApp',
        ], $this->headers());

        $response->assertStatus(201);

        $body = $response->json();
        $this->assertSame('whatsapp', $body['channel']);
        $this->assertSame(Conversation::TYPE_WHATSAPP, $body['type']);
        $this->assertTrue($body['delivery']['send_message']);

        $conversation = Conversation::find($body['id']);
        $this->assertTrue($conversation->isWhatsapp());
        $this->assertNotNull($conversation->customer);
        $this->assertSame('+34 600 11 22 33', $conversation->customer->getMainPhoneNumber());

        Queue::assertPushed(SendWhatsappReply::class);
    }

    public function test_whatsapp_can_be_created_without_sending()
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/conversations', [
            'mailbox_id' => $this->mailbox->id,
            'subject' => 'WhatsApp import',
            'type' => Conversation::TYPE_WHATSAPP,
            'phone' => '34600112233',
            'body' => 'Imported message',
            'send_message' => false,
        ], $this->headers());

        $response->assertStatus(201);
        $this->assertFalse($response->json()['delivery']['send_message']);
        Queue::assertNotPushed(SendWhatsappReply::class);
    }

    public function test_phone_conversation_requires_a_phone_number()
    {
        $response = $this->postJson('/api/v1/conversations', [
            'mailbox_id' => $this->mailbox->id,
            'subject' => 'Phone conversation',
            'type' => Conversation::TYPE_PHONE,
            'body' => 'Hello by phone',
        ], $this->headers());

        $response->assertStatus(422);
        $this->assertSame('phone_required', $response->json()['error']);
    }

    public function test_unsupported_channel_is_rejected()
    {
        $response = $this->postJson('/api/v1/conversations', [
            'mailbox_id' => $this->mailbox->id,
            'subject' => 'Nope',
            'channel' => 'carrier-pigeon',
            'body' => 'Hello',
        ], $this->headers());

        // The FormRequest validates the "channel" whitelist first.
        $response->assertStatus(422);
    }

    public function test_thread_on_whatsapp_conversation_reports_missing_configuration()
    {
        Queue::fake();

        $customer = Customer::create('wa-thread@example.com', ['first_name' => 'W']);
        $conversation = factory(Conversation::class)->create([
            'mailbox_id' => $this->mailbox->id,
            'customer_id' => $customer->id,
            'type' => Conversation::TYPE_WHATSAPP,
        ]);

        $response = $this->postJson(
            '/api/v1/conversations/' . $conversation->id . '/threads',
            ['type' => 2, 'body' => 'A WhatsApp reply'],
            $this->headers()
        );

        $response->assertStatus(422);
        $this->assertSame('whatsapp_not_configured', $response->json()['error']);
        Queue::assertNotPushed(SendWhatsappReply::class);
    }

    public function test_thread_on_whatsapp_conversation_is_queued_when_configured()
    {
        Queue::fake();
        $this->configureWhatsapp();

        $customer = Customer::create('wa-thread2@example.com', [
            'first_name' => 'W',
            'phones' => ['34600112233'],
        ]);
        $conversation = factory(Conversation::class)->create([
            'mailbox_id' => $this->mailbox->id,
            'customer_id' => $customer->id,
            'type' => Conversation::TYPE_WHATSAPP,
        ]);

        $response = $this->postJson(
            '/api/v1/conversations/' . $conversation->id . '/threads',
            ['type' => 2, 'body' => 'A WhatsApp reply'],
            $this->headers()
        );

        $response->assertStatus(201);
        $this->assertTrue($response->json()['delivery']['send_message']);
        $this->assertSame('whatsapp', $response->json()['delivery']['channel']);

        Queue::assertPushed(SendWhatsappReply::class);
    }

    public function test_note_on_whatsapp_conversation_is_not_delivered()
    {
        Queue::fake();
        $this->configureWhatsapp();

        $customer = Customer::create('wa-note@example.com', ['first_name' => 'W']);
        $conversation = factory(Conversation::class)->create([
            'mailbox_id' => $this->mailbox->id,
            'customer_id' => $customer->id,
            'type' => Conversation::TYPE_WHATSAPP,
        ]);

        $response = $this->postJson(
            '/api/v1/conversations/' . $conversation->id . '/threads',
            ['type' => 3, 'body' => 'Internal note'],
            $this->headers()
        );

        $response->assertStatus(201);
        $this->assertFalse($response->json()['delivery']['send_message']);
        Queue::assertNotPushed(SendWhatsappReply::class);
    }

    public function test_channels_helper_exposes_every_medium()
    {
        $this->assertSame(Conversation::TYPE_EMAIL, Channels::type('email'));
        $this->assertSame(Conversation::TYPE_WHATSAPP, Channels::type('whatsapp'));
        $this->assertSame(Conversation::TYPE_WHATSAPP, Channels::type(5));
        $this->assertNull(Channels::type('unknown'));
        $this->assertSame('whatsapp', Channels::name(Conversation::TYPE_WHATSAPP));
        $this->assertTrue(Channels::requiresPhone(Conversation::TYPE_WHATSAPP));
        $this->assertTrue(Channels::requiresEmail(Conversation::TYPE_EMAIL));
        $this->assertTrue(Channels::isDeliverable(Conversation::TYPE_WHATSAPP));
        $this->assertFalse(Channels::isDeliverable(Conversation::TYPE_CHAT));
    }
}
