{{-- Entrada del tablero en la barra de navegación superior.

     Se registra desde KanbanServiceProvider::registerMenu() con
     Eventy::addAction('menu.append'). Para que Helper::menuSelectedHtml('kanban')
     devuelva 'active' la ruta debe estar en el mapa del menú, y eso también lo
     hace el proveedor con el filtro 'menu.selected'. --}}
<li class="{{ \App\Misc\Helper::menuSelectedHtml('kanban') }}">
    <a href="{{ route('kanban.index') }}">{{ __('kanban::kanban.title') }}</a>
</li>