# Paquete de despliegue 002

- **Origen**: commit `4c8cad7c` — «Modulo Kanban y arreglos de despliegue» (rama `dist`).
- **Base**: producción está en `024c12275` (tag `1.8.208`). El paquete lleva **todo lo que difiere** entre
  esos dos puntos, así que se puede aplicar tal cual aunque algún fichero ya estuviera subido.
- **Contenido**: 229 ficheros en 371 entradas. **No hay borrados**: no hay que eliminar nada en el
  servidor.
- **Anteriores**: `deploy-194b8a69.tar.gz` = paquete 001 (se creó **sin** `vendor/`, que es justo lo que
  dejó roto el autoloader en producción; ver más abajo).

## Qué lleva

| Zona | Ficheros | Para qué |
|---|---|---|
| `Modules/Kanban/**` | nuevo | Tablero Kanban: código, vistas, CSS/JS, traducciones, migración, documentación y pruebas. |
| `Modules/RestApi/**` | 108 | El trabajo de la API multi-medio y la versión 1.5.0. |
| `vendor/composer/**` + `vendor/autoload.php` | 11 | Autoloader regenerado: ya conoce `Modules\Kanban\` y sus ficheros son **coherentes entre sí**. |
| `composer.json` | 1 | `psr-4` de `Modules\Kanban\`. |
| `app/**`, `resources/**`, `overrides/**`, `docs/**` | ~108 | El resto del trabajo de la API (tipos de medio, DTOs, vistas, traducciones, OpenAPI). |

No se incluyen `.kiro/**` (especificaciones), `tests/**` ni `phpunit.xml` (son de desarrollo). Las
pruebas de cada módulo sí viajan dentro de su carpeta, como ya hacía RestApi.

## Qué arregla de lo que estaba roto

1. **El fatal `Class 'Composer\Autoload\ComposerStaticInitc2c60620059999092049f10e2898b3f7' not found`.**
   Venía de mezclar ficheros de `vendor/composer/` de dos generaciones distintas: `autoload_real.php`
   pide una clase (`ComposerStaticInit<hash>`) que sólo existe en el `autoload_static.php` que se generó
   a la vez. Este paquete trae **el juego completo y coherente** (mismo hash en los dos ficheros).

   > ⚠️ Los ficheros de `vendor/composer/` se copian **siempre en bloque**, nunca de uno en uno.

2. **`composer dump-autoload` volvía a fallar siempre.** `vendor/composer/installed.json` declaraba dos
   `classmap` de rutas que no existen (`rap2hpoutre/laravel-log-viewer → src/controllers` y
   `natxet/cssmin → src/`): FreeScout movió esas fuentes a `overrides/`. Con la corrección incluida,
   `composer dump-autoload --no-scripts` vuelve a funcionar en cualquier copia del repositorio.

   > ⚠️ `--no-scripts` siempre: los scripts de `post-autoload-dump` de FreeScout borran ficheros y no
   > son idempotentes.

3. **El módulo Kanban no era cargable** porque su namespace (`Modules\Kanban\`) no estaba en el
   autoloader. Ya está.

## Cómo aplicarlo

```bash
cd /var/www/vhosts/qsoftware.biz/tickets.qsoftware.biz

# 0) Copia de seguridad del autoloader actual (por si hay que volver atrás)
cp -a vendor/composer vendor/composer.bak-$(date +%Y%m%d-%H%M)

# 1) Subir y extraer (sobrescribe; no borra nada)
scp deploy-002.tar.gz usuario@servidor:/tmp/
tar -xzf /tmp/deploy-002.tar.gz -C /var/www/vhosts/qsoftware.biz/tickets.qsoftware.biz

# 2) Propietario/permisos si el servidor lo necesita
chown -R xavfo_whatsapp:psacln /var/www/vhosts/qsoftware.biz/tickets.qsoftware.biz/{app,config,Modules,resources,vendor}
find . -type d -exec chmod 755 {} \; && find . -type f -exec chmod 644 {} \;

# 3) Activar el módulo:  System → Modules → Kanban → Activate
#    (equivale a esto, si se prefiere por consola)
php artisan freescout:module-install kanban    # migra + crea el enlace de assets
php artisan freescout:clear-cache

# 4) Comprobaciones
php artisan --version                          # el autoloader carga
php artisan route:list | grep kanban           # 5 rutas
ls -l public/modules/kanban                    # enlace a Modules/Kanban/Public
php artisan migrate:status | grep kanban       # migración aplicada
```

En `.env`: `APP_DEBUG=false` y `APP_ENV=production`.

## Después de subir

1. Entrar en **Tablero** (barra superior) y comprobar que aparecen las columnas
   «Sin etapa / Nuevo / En curso / Esperando cliente / Resuelto».
2. Arrastrar una conversación de una columna a otra y ver el aviso de confirmación.
3. **Etapas** → guardar cambios y recargar para ver que se mantienen.
4. Que la API sigue respondiendo (los endpoints multi-medio de RestApi están incluidos).

## Si algo falla

| Síntoma | Qué mirar |
|---|---|
| Sigue el `ComposerStaticInit... not found` | Que se haya extraído **todo** `vendor/composer/`. Se puede comprobar que el nombre de clase de `autoload_static.php` coincide con el que pide `autoload_real.php`. |
| El tablero no aparece en el menú | Que la fila de `modules` tenga `active=1` y que exista `bootstrap/cache/kanban_module.php`. `php artisan freescout:clear-cache`. |
| El tablero se ve sin estilos | Falta el enlace `public/modules/kanban` (lo crea `freescout:module-install kanban`; en Windows exige permisos, en Linux no). |
| `419 Page Expired` en el tablero | Sesión caducada al dejar la pestaña abierta: recargar. |
| Errores en `/kanban` | `storage/logs/laravel-<fecha>.log`. |

## Notas

- El paquete no incluye `bootstrap/cache/*` (es caché) ni `.env`.
- Las pruebas del módulo (65, todas en verde) se ejecutan **fuera** del servidor:
  `php phpunit.phar -c phpunit.xml --testsuite Kanban`. Detalles en
  `.kiro/specs/kanban/tasks.md`.
