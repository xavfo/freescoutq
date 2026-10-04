<?php

namespace App\Jobs;

use App\Conversation;
use App\Mailbox;
use App\Misc\EvolutionApi;
use App\SendLog;
use App\Thread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers an outgoing message of a WhatsApp conversation through the
 * Evolution API configured for the mailbox.
 */
class SendWhatsappReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of attempts.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Seconds the job can run for.
     *
     * @var int
     */
    public $timeout = 120;

    /**
     * @var Conversation
     */
    protected $conversation;

    /**
     * @var Thread
     */
    protected $thread;

    public function __construct(Conversation $conversation, Thread $thread)
    {
        $this->conversation = $conversation;
        $this->thread = $thread;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        $conversation = $this->conversation;

        if (!$conversation || !$conversation->isWhatsapp()) {
            return;
        }

        // Reload: the reply may have been undone or already delivered.
        $thread = Thread::find($this->thread->id);

        if (
            !$thread
            || $thread->type != Thread::TYPE_MESSAGE
            || $thread->state != Thread::STATE_PUBLISHED
        ) {
            return;
        }

        if ($thread->send_status == SendLog::STATUS_ACCEPTED) {
            return;
        }

        $mailbox = Mailbox::find($conversation->mailbox_id);

        if (!$mailbox || !$mailbox->isWhatsappEnabled()) {
            $this->logStatus($thread, SendLog::STATUS_SEND_ERROR, __('WhatsApp is not configured for this mailbox'));

            return;
        }

        $number = $this->getRecipientNumber($conversation, $thread);

        if (!$number) {
            $this->logStatus($thread, SendLog::STATUS_SEND_ERROR, __('No phone number found for the customer'));

            return;
        }

        $api = EvolutionApi::forMailbox($mailbox);

        $body = $this->prepareBody($thread->body);

        if ($body === '') {
            $this->logStatus($thread, SendLog::STATUS_SEND_ERROR, __('The message is empty'));

            return;
        }

        $result = $api->sendText($number, $body);

        if ($result === false) {
            $message = 'WhatsApp: ' . $api->getLastError();

            $this->logStatus($thread, SendLog::STATUS_SEND_INTERMEDIATE_ERROR, $message);

            // Retry later, the API may be temporarily unreachable.
            if ($this->attempts() < $this->tries) {
                $this->release(300);
            } else {
                $this->logStatus($thread, SendLog::STATUS_SEND_ERROR, $message);
            }

            return;
        }

        $message = 'WhatsApp: ' . __('Sent') . ' (' . $api->getInstance() . ')';

        // Attachments are not delivered yet: warn the agent instead of
        // silently losing them.
        if ($thread->has_attachments) {
            $message .= '. ' . __('Attachments were not sent.');
        }

        $this->logStatus($thread, SendLog::STATUS_ACCEPTED, $message);
    }

    /**
     * Handle a definitive failure of the job.
     */
    public function failed(\Exception $e)
    {
        $thread = Thread::find($this->thread->id);

        if ($thread) {
            $this->logStatus($thread, SendLog::STATUS_SEND_ERROR, 'WhatsApp: ' . $e->getMessage());
        }
    }

    /**
     * Recipient: the customer phone number, falling back to the thread "to".
     *
     * @param  Conversation $conversation
     * @param  Thread       $thread
     * @return string
     */
    protected function getRecipientNumber($conversation, $thread)
    {
        $customer = $conversation->customer;

        if ($customer) {
            $phone = $customer->getMainPhoneNumber();

            if ($phone) {
                return $phone;
            }
        }

        $to = $thread->getToArray();

        if (!empty($to[0])) {
            return $to[0];
        }

        // Phone conversations may keep the number in the customer email field.
        return $conversation->customer_email;
    }

    /**
     * Convert the HTML body of the thread into plain text.
     *
     * @param  string $body
     * @return string
     */
    protected function prepareBody($body)
    {
        $body = (string) $body;

        if (class_exists('\Html2Text\Html2Text')) {
            try {
                $text = (new \Html2Text\Html2Text($body))->getText();
            } catch (\Exception $e) {
                $text = strip_tags($body);
            }
        } else {
            $text = strip_tags($body);
        }

        return trim(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Record the delivery status in the send log and in the thread itself
     * (the same way App\Jobs\SendReplyToCustomer does for emails).
     *
     * @param  Thread $thread
     * @param  int    $status
     * @param  string $message
     * @return void
     */
    protected function logStatus($thread, $status, $message)
    {
        try {
            SendLog::log(
                $thread->id,
                '',
                mb_substr($this->getRecipientNumber($this->conversation, $thread) ?: '', 0, 191),
                SendLog::MAIL_TYPE_EMAIL_TO_CUSTOMER,
                $status,
                $this->conversation->customer_id,
                $thread->created_by_user_id,
                mb_substr($message, 0, 255)
            );
        } catch (\Exception $e) {
            \Log::error('SendWhatsappReply: could not save the send log: ' . $e->getMessage());
        }

        try {
            $thread->send_status = $status;
            $thread->updateSendStatusData(['msg' => mb_substr($message, 0, 255)]);
            $thread->save();
        } catch (\Exception $e) {
            \Log::error('SendWhatsappReply: could not update the thread status: ' . $e->getMessage());
        }
    }
}
