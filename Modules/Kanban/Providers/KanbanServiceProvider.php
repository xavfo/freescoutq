<?php

namespace Modules\Kanban\Providers;

use Illuminate\Support\ServiceProvider;

class KanbanServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../Config/config.php',
            'kanban'
        );
    }

    /**
     * Boot the application events.
     */
    public function boot()
    {
        // Único punto de registro de rutas (ver start.php).
        $this->loadRoutesFrom(__DIR__ . '/../Http/routes.php');

        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'kanban');
        $this->loadTranslationsFrom(__DIR__ . '/../Resources/lang', 'kanban');
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        $this->registerMenu();
    }

    /**
     * Añade el tablero a la barra de navegación superior.
     */
    protected function registerMenu()
    {
        // Item en el menú principal.
        \Eventy::addAction('menu.append', function () {
            echo view('kanban::partials.menu_item')->render();
        });

        // Sin esto, Helper::menuSelectedHtml('kanban') nunca marcaría el item
        // como activo: la ruta tiene que estar en el mapa del menú.
        \Eventy::addFilter('menu.selected', function ($menu) {
            $menu['kanban'] = ['kanban.index', 'kanban.settings'];

            return $menu;
        });
    }

    /**
     * Module version.
     *
     * Fuente única: Modules/Kanban/module.json.
     *
     * @return string
     */
    public static function moduleVersion()
    {
        static $version = null;

        if ($version === null) {
            $manifest = @json_decode(@file_get_contents(__DIR__ . '/../module.json'), true);
            $version = $manifest['version'] ?? '0.0.0';
        }

        return $version;
    }
}
