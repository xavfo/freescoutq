{{--
    Una tarjeta del tablero.

    Variables: $card (Modules\Kanban\Entities\DTOs\CardDTO)

    La línea izquierda de 5 px es del COLOR DEL TIPO DE MEDIO (--kb-m): es lo
    que permite distinguir de un golpe de vista un WhatsApp de un correo sin
    tener que leer la tarjeta.
--}}
<div class="kb-card"
    draggable="true"
    data-id="{{ $card->id }}"
    data-medium="{{ $card->medium }}"
    style="--kb-m: {{ $card->medium_color }}">

    <div class="kb-card-top">
        <span class="kb-num">#{{ $card->number }}</span>
        @if ($card->mailbox_name)
        <span class="kb-mailbox" title="{{ $card->mailbox_name }}">{{ $card->mailbox_name }}</span>
        @endif
        <span class="kb-spacer"></span>
        <button type="button"
            class="kb-card-menu"
            title="{{ __('kanban::kanban.card_actions') }}"
            aria-label="{{ __('kanban::kanban.card_actions') }}">&#8942;</button>
    </div>

    <a class="kb-subject" href="{{ $card->url }}" title="{{ $card->subject ?: __('kanban::kanban.no_subject') }}">{{ $card->subject ?: __('kanban::kanban.no_subject') }}</a>

    <div class="kb-customer" title="{{ $card->customer_name }}">{{ $card->customer_name }}</div>

    <div class="kb-card-foot">
        <span class="kb-pill" title="{{ $card->medium_label }}">{{ $card->medium_label }}</span>

        @if ($card->stale)
        <span class="kb-age kb-stale" title="{{ __('kanban::kanban.stale_hint', ['count' => $card->age_days]) }}">{{ $card->age }}</span>
        @else
        <span class="kb-age">{{ $card->age }}</span>
        @endif

        @if ($card->has_attachments)
        <span title="{{ __('Attachments') }}"><i class="glyphicon glyphicon-paperclip"></i></span>
        @endif

        @if ($card->threads_count > 1)
        <span title="{{ __('Threads') }}"><i class="glyphicon glyphicon-comment"></i> {{ $card->threads_count }}</span>
        @endif

        <span class="kb-avatar" title="{{ $card->assignee_name ?: __('kanban::kanban.no_assignee') }}">{{ $card->assignee_initials ?: '—' }}</span>
    </div>
</div>