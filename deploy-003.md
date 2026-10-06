# Paquete de despliegue 003 — arreglo urgente de la minificación

- **Origen**: commit `2bbda689` — «Arreglar la minificacion de CSS y quitar el Whoops roto».
- **Cuándo usarlo**: producción está dando
  `Class 'CssMinifier' not found (View: resources/views/layouts/app.blade.php)` en todas las páginas.
- **Requisito**: el paquete 002 ya está aplicado (el autoloader carga). Este es un arreglo **encima** del 002.
- **Contenido**: 17 ficheros, ~150 KB. **Se aplica en 1 minuto y no toca datos.**

```bash
sha256  99CB3383017109FBAFF057FF345B3D9DACE9EFA5589CD79CA9D5671CD1E57B7D
```

## Por qué fallaba

`resources/views/layouts/app.blade.php` minifica el CSS con `Minify::stylesheet()`, que acaba en
`Devfactory\Minify\Providers\StyleSheet::minify()` → `new CssMinifier(...)`. Esa clase (global, sin
namespace) **sólo** existe en `overrides/natxet/cssmin/src/CssMin.php`, y ese fichero estaba listado en
`exclude-from-classmap` de `composer.json`, así que:

1. no entraba en el classmap (y no hay psr-4/psr-0 que cubra `CssMinifier` → «class not found»);
2. además, el script `post-autoload-dump` de FreeScout **borra** los ficheros que están en esa lista,
   con lo que el fichero podía desaparecer del servidor.

En local no se veía porque la minificación se salta en el entorno `local`
(`config/minify.config.php` → `ignore_environments: ['local']`) y el CSS ya estaba cacheado en
`public/css/builds/`.

## Qué trae

| Fichero | Por qué |
|---|---|
| `composer.json` | Las rutas de `overrides/` (`CssMin`, `Rap2hpoutre`, `Whoops`) dejan de estar excluidas del classmap, y se quitan los mapeos `Whoops\*` (ver abajo). |
| `vendor/composer/*` + `vendor/autoload.php` | Autoloader regenerado con esas clases dentro: **`CssMinifier`, `CssMin`, `JShrink\Minifier` y `Rap2hpoutre\LaravelLogViewer\*` ya se resuelven**. |
| `overrides/natxet/cssmin/src/CssMin.php` | La clase en sí, por si el script de Composer la había borrado en el servidor. |
| `overrides/rap2hpoutre/.../LaravelLogViewer.php` y `LogViewerController.php` | Igual: sus clases están en el classmap y el fichero pudo borrarse. Es el visor de logs (System → Logs). |

## Aplicarlo

```bash
cd /var/www/vhosts/qsoftware.biz/tickets.qsoftware.biz

# 0) Copia de seguridad del autoloader actual
cp -a vendor/composer vendor/composer.bak-$(date +%Y%m%d-%H%M)

# 1) Subir y extraer (sobrescribe los ficheros del paquete; no borra nada)
scp deploy-003.tar.gz usuario@servidor:/tmp/
tar -xzf /tmp/deploy-003.tar.gz -C /var/www/vhosts/qsoftware.biz/tickets.qsoftware.biz

# 2) Borrar el parche roto de Whoops (esto SÍ hay que hacerlo a mano: el tar no borra)
rm -rf overrides/filp/whoops

# 3) Limpiar cachés (incluido el CSS minificado, para que se regenere ya arreglado)
rm -f public/css/builds/*.css public/js/builds/*.js
php artisan freescout:clear-cache

# 4) Comprobación
php -r "require 'vendor/autoload.php'; var_dump(class_exists('CssMinifier'));"   # debe dar bool(true)
```

Luego abrir cualquier página de la aplicación (debe pintar bien) y `/kanban`.

## Sobre el Whoops que se borra

`overrides/filp/whoops/` contenía sólo 5 ficheros de un parche incompleto: `Whoops\Run` no implementa
tres métodos que declara `Whoops\RunInterface`, así que **con sólo cargar la clase el proceso muere**
(`PHP Fatal error: Class Whoops\Run contains 3 abstract methods...`) en PHP 8.1. Es lo que se veía al
poner `APP_DEBUG=true`. Sin esos ficheros, `class_exists('Whoops\Run')` es `false` y Laravel usa su
propia página de error (`resources/views/errors/500.blade.php`, que ya existe) ✅.

## Notas

- El paquete **no** incluye `Modules/`, `app/` ni `resources/`: no hace falta reaplicar el 002.
- Si en algún momento se ejecuta `composer dump-autoload` en el servidor, hacerlo **siempre** con
  `--no-scripts` (los scripts de FreeScout borran ficheros y no son idempotentes). Con `composer.json`
  ya corregido, regenerar el autoloader es seguro, pero no es necesario.
- Las pruebas del módulo siguen en verde (65 pruebas) con este cambio.
