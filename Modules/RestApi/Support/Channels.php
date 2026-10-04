<?php

namespace Modules\RestApi\Support;

use App\Conversation;
use App\Jobs\SendReplyToCustomer;
use App\Jobs\SendWhatsappReply;
use App\Mailbox;
use App\Thread;
use Illuminate\Http\JsonResponse;

/**
 * Media (channel) helpers for the REST API.
 *
 * A "channel" is the medium a conversation travels through: email, phone,
 * chat, custom or WhatsApp. The API accepts the FreeScout conversation type
 * (1..5) in the payloads and exposes a stable, machine readable name in the
 * responses, so integrations such as QChat do not have to hardcode numbers.
 *
 * The class also centralizes the checks that tell whether a mailbox is able
 * to deliver through a given channel. A WhatsApp conversation cannot be
 * created (with delivery) when the mailbox has no Evolution API credentials,
 * and an email cannot be sent when the mailbox has no outgoing configuration.
 * Those cases are reported to the client with a structured error instead of
 * failing silently inside the queue worker.
 */
class Channels
{
    const EMAIL    = 'email';
    const PHONE    = 'phone';
    const CHAT     = 'chat';
    const CUSTOM   = 'custom';
    const WHATSAPP = 'whatsapp';

    /**
     * Structured error codes returned to the client.
     */
    const ERROR_WHATSAPP_NOT_CONFIGURED = 'whatsapp_not_configured';
    const ERROR_EMAIL_NOT_CONFIGURED    = 'email_not_configured';
    const ERROR_UNSUPPORTED_CHANNEL     = 'unsupported_channel';
    const ERROR_PHONE_REQUIRED          = 'phone_required';
    const ERROR_CUSTOMER_UNRESOLVED     = 'customer_unresolved';

    /**
     * Machine readable name of a conversation type.
     *
     * @param  int|string $type
     * @return string
     */
    public static function name($type)
    {
        switch ((int) $type) {
            case Conversation::TYPE_EMAIL:
                return self::EMAIL;
            case Conversation::TYPE_PHONE:
                return self::PHONE;
            case Conversation::TYPE_CHAT:
                return self::CHAT;
            case Conversation::TYPE_CUSTOM:
                return self::CUSTOM;
            case Conversation::TYPE_WHATSAPP:
                return self::WHATSAPP;
        }

        return 'unknown';
    }

    /**
     * Conversation type of a channel.
     *
     * Accepts the numeric FreeScout type (1..5) or the machine name
     * ("email", "phone", "chat", "custom", "whatsapp").
     *
     * @param  mixed $channel
     * @return int|null null when the channel is not supported.
     */
    public static function type($channel)
    {
        if ($channel === null || $channel === '') {
            return null;
        }

        if (is_numeric($channel)) {
            $type = (int) $channel;

            return in_array($type, array_keys(self::all()), true) ? $type : null;
        }

        switch (strtolower((string) $channel)) {
            case self::EMAIL:
                return Conversation::TYPE_EMAIL;
            case self::PHONE:
                return Conversation::TYPE_PHONE;
            case self::CHAT:
                return Conversation::TYPE_CHAT;
            case self::CUSTOM:
                return Conversation::TYPE_CUSTOM;
            case self::WHATSAPP:
                return Conversation::TYPE_WHATSAPP;
        }

        return null;
    }

    /**
     * Every supported channel: conversation type => machine name.
     *
     * @return array
     */
    public static function all()
    {
        return [
            Conversation::TYPE_EMAIL    => self::EMAIL,
            Conversation::TYPE_PHONE    => self::PHONE,
            Conversation::TYPE_CHAT     => self::CHAT,
            Conversation::TYPE_CUSTOM   => self::CUSTOM,
            Conversation::TYPE_WHATSAPP => self::WHATSAPP,
        ];
    }

    /**
     * Whether an email address identifies the recipient.
     *
     * @param  int|string $type
     * @return bool
     */
    public static function requiresEmail($type)
    {
        return (int) $type === Conversation::TYPE_EMAIL;
    }

    /**
     * Whether a phone number identifies the recipient.
     *
     * @param  int|string $type
     * @return bool
     */
    public static function requiresPhone($type)
    {
        return in_array((int) $type, [Conversation::TYPE_PHONE, Conversation::TYPE_WHATSAPP], true);
    }

    /**
     * Whether the channel delivers the message to an external provider
     * (an outgoing email or a WhatsApp message through Evolution API).
     *
     * @param  int|string $type
     * @return bool
     */
    public static function isDeliverable($type)
    {
        return in_array((int) $type, [Conversation::TYPE_EMAIL, Conversation::TYPE_WHATSAPP], true);
    }

    /**
     * Check that the mailbox is able to deliver through the given channel.
     *
     * @param  Mailbox    $mailbox
     * @param  int|string $type
     * @return array{ok: bool, error: string|null, message: string|null}
     */
    public static function check(Mailbox $mailbox, $type)
    {
        if ((int) $type === Conversation::TYPE_WHATSAPP && !$mailbox->isWhatsappEnabled()) {
            return [
                'ok'      => false,
                'error'   => self::ERROR_WHATSAPP_NOT_CONFIGURED,
                'message' => __('WhatsApp is not configured for this mailbox'),
            ];
        }

        if ((int) $type === Conversation::TYPE_EMAIL && !$mailbox->isOutActive()) {
            return [
                'ok'      => false,
                'error'   => self::ERROR_EMAIL_NOT_CONFIGURED,
                'message' => __('Sending email is not configured for this mailbox'),
            ];
        }

        return ['ok' => true, 'error' => null, 'message' => null];
    }

    /**
     * Build the structured JSON error returned when a channel can not be used.
     *
     * @param  string $error
     * @param  string $message
     * @param  string $field
     * @param  int    $status
     * @return JsonResponse
     */
    public static function errorResponse($error, $message, $field = 'mailbox_id', $status = 422)
    {
        return new JsonResponse([
            'message'     => $message,
            'status_code' => $status,
            'error'       => $error,
            'errors'      => [
                $field => [$message],
            ],
        ], $status);
    }

    /**
     * Whether the caller asked to deliver the message to the customer.
     *
     * An explicit "send_message" (or its alias "send") wins. Otherwise a
     * WhatsApp conversation defaults to being delivered (that is its purpose)
     * and email keeps its historical "create only" behaviour.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int|string                $type
     * @return bool
     */
    public static function shouldSend($request, $type)
    {
        $explicit = $request->input('send_message', $request->input('send'));

        if ($explicit !== null) {
            return filter_var($explicit, FILTER_VALIDATE_BOOLEAN);
        }

        return (int) $type === Conversation::TYPE_WHATSAPP;
    }

    /**
     * Queue the delivery of a thread through its conversation channel.
     *
     * Email goes through App\Jobs\SendReplyToCustomer and WhatsApp through
     * App\Jobs\SendWhatsappReply (Evolution API). Notes and non deliverable
     * channels are never sent.
     *
     * @param  Conversation $conversation
     * @param  Thread       $thread
     * @param  bool         $send
     * @return bool whether a delivery job was queued.
     */
    public static function queueDelivery(Conversation $conversation, Thread $thread, $send)
    {
        if (!$send || $thread->type != Thread::TYPE_MESSAGE) {
            return false;
        }

        if ($conversation->isWhatsapp()) {
            SendWhatsappReply::dispatch($conversation, $thread)->onQueue('emails');

            return true;
        }

        if ($conversation->isEmail()) {
            $customer = $conversation->customer;
            if ($customer) {
                SendReplyToCustomer::dispatch($conversation, $conversation->getReplies(), $customer)
                    ->onQueue('emails');

                return true;
            }
        }

        return false;
    }

    /**
     * Information block attached to the create responses.
     *
     * @param  int|string $type
     * @param  bool       $queued
     * @return array
     */
    public static function deliveryInfo($type, $queued)
    {
        return [
            'channel'      => self::name($type),
            'type'         => (int) $type,
            'deliverable'  => self::isDeliverable($type),
            'send_message' => (bool) $queued,
        ];
    }
}
