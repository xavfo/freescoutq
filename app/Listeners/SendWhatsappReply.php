<?php

namespace App\Listeners;

use App\Conversation;

/**
 * Queues the delivery of an outgoing message of a WhatsApp conversation
 * through the Evolution API.
 *
 * Mirrors App\Listeners\SendReplyToCustomer: the email is skipped for
 * WhatsApp conversations (see SendReplyToCustomer::handle()) and this
 * listener takes care of the real delivery instead.
 */
class SendWhatsappReply
{
    /**
     * Handle the event.
     */
    public function handle($event)
    {
        $conversation = $event->conversation;

        if (!$conversation || !$conversation->isWhatsapp()) {
            return;
        }

        // The thread that triggered the event. For UserReplied it is
        // $event->thread, for UserCreatedConversation $event->thread too.
        $thread = $event->thread ?? $event->last_thread ?? null;

        if (!$thread) {
            return;
        }

        // A note is internal, it is never delivered over WhatsApp.
        if ($thread->type != \App\Thread::TYPE_MESSAGE) {
            return;
        }

        // Give the user the same chance to undo the reply as with emails.
        $delay = \Eventy::filter(
            'conversation.send_reply_to_customer_delay',
            now()->addSeconds(Conversation::UNDO_TIMOUT),
            $conversation,
            $thread
        );

        \App\Jobs\SendWhatsappReply::dispatch($conversation, $thread)
            ->delay($delay)
            ->onQueue('emails');
    }
}
