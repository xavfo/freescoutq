@extends('layouts.app')

@section('title', __('kanban::kanban.title'))

@section('stylesheets')
<link rel="stylesheet" href="{{ asset(\Module::getPublicPath('kanban').'/css/kanban.css') }}?v={{ $moduleVersion }}">
@endsection

@section('content')
{{--
    Tablero Kanban.

    El marcado de las tarjetas lo genera el servidor (una sola fuente de verdad)
    y el JS sólo lo sustituye cuando refresca o carga más. Los textos de las
    acciones rápidas viajan en atributos data- para no tener idioma duplicado
    dentro del JS.

    El color de cada fase NO está en el CSS: cada columna pone sus variables
    --kb-c / --kb-soft en línea, con los colores configurados por buzón.
--}}
<div id="kanban"
    data-user-id="{{ Auth::id() }}"
    data-board-url="{{ route('kanban.board') }}"
    data-move-url="{{ route('kanban.move') }}"
    data-conversations-url="{{ route('conversations.ajax') }}"
    data-refresh="{{ (int) config('kanban.refresh_seconds') }}"
    data-label-move="{{ __('kanban::kanban.moved') }}"
    data-label-move-to="{{ __('kanban::kanban.move_to') }}"
    data-label-error="{{ __('kanban::kanban.error_generic') }}"
    data-label-assign-me="{{ __('kanban::kanban.card_assign_me') }}"
    data-label-unassign="{{ __('kanban::kanban.card_unassign') }}"
    data-label-active="{{ __('kanban::kanban.card_active') }}"
    data-label-pending="{{ __('kanban::kanban.card_pending') }}"
    data-label-closed="{{ __('kanban::kanban.card_closed') }}"
    data-label-open="{{ __('kanban::kanban.card_open') }}">

    @include('kanban::partials.filters')

    {{-- Leyenda: qué color corresponde a cada tipo de mensaje. --}}
    <div class="kb-legend">
        <span>{{ __('kanban::kanban.legend_medium') }}</span>
        @foreach ($media as $key => $label)
        <span class="kb-item">
            <span class="kb-bar" style="background: {{ \Modules\Kanban\Support\Medium::color($key) }}"></span>{{ $label }}
        </span>
        @endforeach
    </div>

    <div class="kb-board">
        @foreach ($columns as $column)
        @include('kanban::partials.column', ['column' => $column])
        @endforeach
    </div>
</div>
@endsection

@section('javascripts')
<script src="{{ asset(\Module::getPublicPath('kanban').'/js/kanban.js') }}?v={{ $moduleVersion }}"></script>
@endsection