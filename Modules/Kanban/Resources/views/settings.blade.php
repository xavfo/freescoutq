@extends('layouts.app')

@section('title', __('kanban::kanban.settings_title'))

@section('stylesheets')
<link rel="stylesheet" href="{{ asset(\Module::getPublicPath('kanban').'/css/kanban.css') }}?v={{ $moduleVersion }}">
@endsection

@section('content')
{{--
    Editor de etapas de un buzón.

    Las etapas se guardan en mailboxes.meta['kanban']['stages'] (mismo patrón que
    usan los ajustes de WhatsApp del core), NO en un fichero de configuración ni
    en ajustes globales: cada buzón tiene su propio flujo.

    Esta pantalla vive en el módulo (/kanban/settings) a propósito, para no tener
    que tocar ni un fichero del core y que las actualizaciones de FreeScout no
    pisen los cambios.
--}}
<div id="kanban" class="container">
    <h2>{{ __('kanban::kanban.settings_title') }}</h2>

    <p class="kb-hint">{{ __('kanban::kanban.settings_help') }}</p>

    @if ($mailboxes->count() > 1)
    <form method="GET" action="{{ route('kanban.settings') }}" class="form-inline margin-bottom-20">
        <label for="kb-mailbox">{{ __('kanban::kanban.settings_mailbox') }}</label>
        <select id="kb-mailbox" name="mailbox_id" class="form-control margin-left-10" onchange="this.form.submit()">
            @foreach ($mailboxes as $option)
            <option value="{{ $option->id }}" @if ((int) $option->id === (int) $mailbox->id) selected @endif>{{ $option->name }}</option>
            @endforeach
        </select>
    </form>
    @endif

    <form method="POST" action="{{ route('kanban.settings.save') }}" id="kb-stages-form">
        {{ csrf_field() }}
        <input type="hidden" name="mailbox_id" value="{{ $mailbox->id }}">

        <div id="kb-stages">
            @forelse ($stages as $i => $stage)
            @include('kanban::partials.stage_row', ['i' => $i, 'stage' => $stage, 'statuses' => $statuses, 'palette' => $palette])
            @empty
            <p class="kb-hint">{{ __('kanban::kanban.settings_none') }}</p>
            @endforelse
        </div>

        <hr>

        <p class="kb-hint">{{ __('kanban::kanban.stage_wip_hint') }}</p>
        <p class="kb-hint">{{ __('kanban::kanban.stage_status_hint') }}</p>

        <p class="margin-top-20">
            <button type="button" class="btn btn-default" id="kb-add-stage">+ {{ __('kanban::kanban.stage_add') }}</button>
            <button type="submit" class="btn btn-primary">{{ __('kanban::kanban.settings_save') }}</button>
            <a class="btn btn-link" href="{{ route('kanban.index') }}">{{ __('kanban::kanban.title') }}</a>
        </p>
    </form>

    <form method="POST" action="{{ route('kanban.settings.save') }}" id="kb-stages-reset">
        {{ csrf_field() }}
        <input type="hidden" name="mailbox_id" value="{{ $mailbox->id }}">
        <input type="hidden" name="reset" value="1">
        <button type="submit" class="btn btn-default">{{ __('kanban::kanban.settings_reset') }}</button>
    </form>

    {{-- Plantilla de fila nueva (misma que partials/stage_row). --}}
    <script type="text/html" id="kb-stage-template">
        @include('kanban::partials.stage_row', ['i' => '__INDEX__', 'stage' => ['color' => $palette[0] ?? '#3498db'], 'statuses' => $statuses, 'palette' => $palette])
    </script>
</div>
@endsection

@section('javascript')
var stagesWrap = document.getElementById('kb-stages');
var stagesTpl = document.getElementById('kb-stage-template');

if (stagesWrap && stagesTpl) {
var nextIndex = stagesWrap.querySelectorAll('.kb-stage-row').length;

document.getElementById('kb-add-stage').addEventListener('click', function () {
// La plantilla trae el índice como texto: se sustituye por uno nuevo
// para que PHP reciba un array stages[N][...] bien formado.
stagesWrap.insertAdjacentHTML(
'beforeend',
stagesTpl.innerHTML.replace(/__INDEX__/g, nextIndex++)
);
});

stagesWrap.addEventListener('click', function (event) {
var remove = event.target.closest('.kb-remove-stage');

if (remove) {
event.preventDefault();
remove.closest('.kb-stage-row').remove();
}
});
}

var resetForm = document.getElementById('kb-stages-reset');

if (resetForm) {
resetForm.addEventListener('submit', function (event) {
if (!confirm(Lang.get('messages.are_you_sure'))) {
event.preventDefault();
}
});
}
@endsection