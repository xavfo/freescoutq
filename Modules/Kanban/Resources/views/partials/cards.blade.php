{{--
    Lista de tarjetas de UNA columna.

    Este partial es la única fuente del marcado de tarjetas: lo usan tanto el
    render inicial (a través de partials/column) como la respuesta AJAX de
    /kanban/board, que devuelve el HTML ya renderizado. Así no hay dos versiones
    del mismo marcado que se puedan desincronizar.

    Variables: $cards (array de Modules\Kanban\Entities\DTOs\CardDTO)

    Nota: no pinta el estado "columna vacía" a propósito. De eso se encarga el
    contenedor (partials/column + kanban.js) para poder reemplazar el contenido
    de la columna sin perder el mensaje.
--}}
@foreach ($cards as $card)
@include('kanban::partials.card', ['card' => $card])
@endforeach