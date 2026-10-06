{{--
    Barra de filtros del tablero.

    Variables: $mailboxes, $assignees, $filters, $can_settings

    Todo son controles del propio formulario; el JS los serializa tal cual y los
    manda a /kanban/board. Los que llevan la clase `kb-auto` recargan al cambiar,
    y el buscador espera 400 ms desde la última tecla.
--}}
<form id="kb-filters" class="kb-toolbar" onsubmit="return false;">

    @if ($mailboxes->count() > 1)
    <select name="mailbox_id" class="kb-ctrl kb-auto" title="{{ __('kanban::kanban.filter_mailbox') }}">
        <option value="">{{ __('kanban::kanban.all_mailboxes') }}</option>
        @foreach ($mailboxes as $mailbox)
        <option value="{{ $mailbox->id }}" @if ((string) ($filters['mailbox_id'] ?? '' )===(string) $mailbox->id) selected @endif>{{ $mailbox->name }}</option>
        @endforeach
    </select>
    @endif

    <select name="assignee" class="kb-ctrl kb-auto" title="{{ __('kanban::kanban.filter_assignee') }}">
        <option value="">{{ __('kanban::kanban.all_assignees') }}</option>
        <option value="me" @if (($filters['assignee'] ?? '' )==='me' ) selected @endif>{{ __('kanban::kanban.assignee_me') }}</option>
        <option value="none" @if (($filters['assignee'] ?? '' )==='none' ) selected @endif>{{ __('kanban::kanban.assignee_none') }}</option>
        @foreach ($assignees as $id => $name)
        <option value="{{ $id }}" @if ((string) ($filters['assignee'] ?? '' )===(string) $id) selected @endif>{{ $name }}</option>
        @endforeach
    </select>

    <input type="text"
        name="search"
        class="kb-ctrl kb-search"
        value="{{ $filters['search'] ?? '' }}"
        placeholder="{{ __('kanban::kanban.search_placeholder') }}">

    <label class="kb-toggle" title="{{ __('kanban::kanban.show_stale_only') }}">
        <input type="checkbox"
            name="stale_days"
            value="{{ (int) config('kanban.stale_days') }}"
            class="kb-auto"
            @if (!empty($filters['stale_days'])) checked @endif>
        {{ __('kanban::kanban.show_stale_only') }}
    </label>

    <span class="kb-spacer"></span>

    <button type="button" id="kb-reload" class="kb-btn">{{ __('kanban::kanban.refresh') }}</button>

    @if ($can_settings)
    <a class="kb-btn" href="{{ route('kanban.settings') }}">{{ __('kanban::kanban.settings') }}</a>
    @endif
</form>