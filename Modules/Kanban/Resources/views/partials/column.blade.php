{{--
    Una columna (fase) del tablero.

    Variables: $column (array)
        id · name · color · color_soft · wip · count · has_more · offset · html

    El "área de color" de la fase se consigue con dos variables CSS en línea:
    --kb-c pinta la cabecera (color fuerte) y --kb-soft el fondo del cuerpo
    (el mismo color muy aclarado). Los valores salen de la configuración del
    buzón, no del CSS, así que cada equipo puede tener sus propias fases.
--}}
<div class="kb-col"
    data-stage="{{ $column['id'] }}"
    data-name="{{ $column['name'] }}"
    data-wip="{{ $column['wip'] }}"
    data-offset="{{ $column['offset'] }}"
    data-empty="{{ __('kanban::kanban.empty_column') }}"
    style="--kb-c: {{ $column['color'] }}; --kb-soft: {{ $column['color_soft'] }};">

    <div class="kb-col-head">
        <span class="kb-col-name">{{ $column['name'] }}</span>
        <span class="kb-count @if ($column['wip'] && $column['count'] > $column['wip']) kb-over @endif"
            @if ($column['wip']) title="{{ __('kanban::kanban.wip_over') }}" @endif>{{ $column['count'] }}@if ($column['wip'])/{{ $column['wip'] }}@endif</span>
    </div>

    <div class="kb-col-body">
        {!! $column['html'] !!}
    </div>

    <div class="kb-col-foot" @if (!$column['has_more']) style="display: none;" @endif>
        <button type="button" class="kb-btn kb-more">{{ __('kanban::kanban.load_more') }}</button>
    </div>
</div>