<?php

namespace Modules\Kanban\Entities\DTOs;

use App\Conversation;
use Modules\Kanban\Support\Medium;

/**
 * Tarjeta del tablero.
 */
class CardDTO
{
    public $id;
    public $number;
    public $subject;
    public $mailbox_id;
    public $mailbox_name;
    public $status;
    public $status_name;
    public $medium;
    public $medium_label;
    public $medium_color;
    public $customer_name;
    public $assignee_id;
    public $assignee_name;
    public $assignee_initials;
    public $has_attachments;
    public $threads_count;
    public $age;
    public $age_days;
    public $stale;
    public $url;

    /**
     * @param  Conversation $conversation
     * @param  int          $staleDays
     * @return self
     */
    public static function fromModel(Conversation $conversation, $staleDays = 0)
    {
        $instance = new self();

        $medium = Medium::of($conversation);

        $instance->id = (int) $conversation->id;
        $instance->number = (int) $conversation->number;
        $instance->subject = (string) $conversation->subject;
        $instance->mailbox_id = (int) $conversation->mailbox_id;
        $instance->mailbox_name = $conversation->mailbox ? $conversation->mailbox->name : '';
        $instance->status = (int) $conversation->status;
        $instance->status_name = Conversation::statusCodeToName($conversation->status);
        $instance->medium = $medium;
        $instance->medium_label = Medium::label($medium);
        $instance->medium_color = Medium::color($medium);
        $instance->has_attachments = (bool) $conversation->has_attachments;
        $instance->threads_count = (int) $conversation->threads_count;
        $instance->url = $conversation->url();

        $instance->customer_name = $conversation->customer
            ? $conversation->customer->getFullName()
            : ($conversation->customer_email ?: '');

        $instance->assignee_id = $conversation->user_id ? (int) $conversation->user_id : null;
        $instance->assignee_name = $conversation->user ? $conversation->user->getFullName() : '';
        $instance->assignee_initials = self::initials($instance->assignee_name);

        // Antigüedad: desde la última actividad (o desde su creación).
        $since = $conversation->last_reply_at ?: $conversation->created_at;

        // "Sin respuesta" = la última palabra la tuvo el cliente (o nadie todavía),
        // es decir, la pelota está en nuestro tejado.
        $fromCustomer = $conversation->last_reply_from === null
            || (int) $conversation->last_reply_from === Conversation::PERSON_CUSTOMER;

        $instance->age = self::humanAge($since);
        $instance->age_days = $since ? (int) $since->diffInDays(now()) : 0;
        $instance->stale = $staleDays > 0 && $fromCustomer && $since
            && $since->diffInDays(now()) >= $staleDays;

        return $instance;
    }

    public function toArray()
    {
        return get_object_vars($this);
    }

    /**
     * "hace 3 d" / "hace 2 h" / "hace 15 min".
     *
     * @param  \Carbon\Carbon|null $date
     * @return string
     */
    protected static function humanAge($date)
    {
        if (!$date) {
            return '';
        }

        $minutes = (int) $date->diffInMinutes(now());

        if ($minutes < 60) {
            return __('kanban::kanban.age_minutes', ['count' => max(1, $minutes)]);
        }

        $hours = (int) $date->diffInHours(now());
        if ($hours < 24) {
            return __('kanban::kanban.age_hours', ['count' => $hours]);
        }

        $days = (int) $date->diffInDays(now());
        if ($days < 30) {
            return __('kanban::kanban.age_days', ['count' => $days]);
        }

        return trans_choice('kanban::kanban.age_months', (int) round($days / 30), ['count' => (int) round($days / 30)]);
    }

    /**
     * Iniciales para el avatar (máximo 2 letras).
     *
     * @param  string $name
     * @return string
     */
    protected static function initials($name)
    {
        $name = trim((string) $name);

        if ($name === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $name);
        $initials = mb_substr($parts[0], 0, 1);

        if (count($parts) > 1) {
            $initials .= mb_substr(end($parts), 0, 1);
        }

        return mb_strtoupper($initials);
    }
}
