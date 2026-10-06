{{--
    Una fila del editor de etapas.

    Variables: $i (índice dentro de stages[]), $stage (array), $statuses, $palette

    Se usa en dos sitios y por eso es un partial:
      1. Pintar las etapas actuales del buzón.
      2. Servir de plantilla a "Añadir etapa" (con $i = __INDEX__ y luego un
         replace en el JS), para no duplicar el marcado de la fila.
--}}
@php
$stage_id = $stage['id'] ?? '';
$stage_name = $stage['name'] ?? '';
$stage_color = $stage['color'] ?? ($palette[0] ?? '#3498db');
$stage_wip = (int) ($stage['wip'] ?? 0);
$stage_status = $stage['status'] ?? '';
@endphp
<div class="kb-stage-row">
    <input type="hidden" name="stages[{{ $i }}][id]" value="{{ $stage_id }}">

    <input type="text"
        class="form-control kb-name"
        name="stages[{{ $i }}][name]"
        value="{{ $stage_name }}"
        placeholder="{{ __('kanban::kanban.stage_name') }}"
        maxlength="60"
        required>

    <select class="form-control kb-color"
        name="stages[{{ $i }}][color]"
        title="{{ __('kanban::kanban.stage_color') }}">
        @foreach ($palette as $color)
        <option value="{{ $color }}" @if (strtoupper($color)===strtoupper($stage_color)) selected @endif style="background: {{ $color }}">&nbsp;</option>
        @endforeach
    </select>

    <input type="number"
        class="form-control kb-wip"
        name="stages[{{ $i }}][wip]"
        value="{{ $stage_wip }}"
        min="0"
        max="999"
        title="{{ __('kanban::kanban.stage_wip') }}">

    <select class="form-control"
        name="stages[{{ $i }}][status]"
        title="{{ __('kanban::kanban.stage_status') }}">
        @foreach ($statuses as $code => $label)
        <option value="{{ $code }}" @if ((string) $stage_status===(string) $code) selected @endif>{{ $label }}</option>
        @endforeach
    </select>

    <button type="button" class="btn btn-link kb-remove-stage" title="{{ __('kanban::kanban.stage_remove') }}">&times;</button>
</div>