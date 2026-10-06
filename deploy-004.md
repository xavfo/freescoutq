# Paquete de despliegue 004 — imagen del módulo RestApi

- **Origen**: commit `4c1f7cc6` — «Imagen representativa para el modulo RestApi».
- **Contenido**: 2 ficheros, ~7,5 KB. **Aditivo**: se puede extraer antes o después del 003, no se solapan.
- **Qué cambia**: la tarjeta del módulo RestApi en *System → Modules* deja de mostrar el puzzle gris
  (`/img/default-module.png`) y muestra un icono propio.

```bash
sha256  27E7A12615C08C27939425D562771B92A8A924B64BB95C3D842E9AA9BCF467BE
```

| Fichero | Qué es |
|---|---|
| `Modules/RestApi/module.json` | Nueva clave `"img": "/modules/restapi/img/icon.png"`. Es el valor que lee `ModulesController` (`$module->get('img')`) para `<img src="…">`; si falta, `resources/views/modules/partials/module_card.blade.php` cae en `App\Module::IMG_DEFAULT`. |
| `Modules/RestApi/Public/img/icon.png` | El icono: 256×256 RGBA con las esquinas transparentes, llaves con tres puntos. Está a 256 porque la tarjeta lo pinta a 128 y así se ve nítido en pantallas HiDPI. |

No se toca `public/img/default-module.png`: es el respaldo común de **todos** los módulos sin imagen.

## Aplicarlo

```bash
cd /var/www/vhosts/qsoftware.biz/tickets.qsoftware.biz
tar -xzf /tmp/deploy-004.tar.gz -C .

# 1) Que el CSS/JS y los assets del módulo se sirvan desde el sitio correcto.
#    El enlace public/modules/restapi debe apuntar a Modules/RestApi/Public.
ls -l public/modules/restapi
#    Si no existe o apunta a Modules/template/Public (ver abajo):
rm -f public/modules/restapi
php artisan freescout:module-install restapi     # recrea el enlace (las migraciones ya están aplicadas)

# 2) Limpiar la caché de la lista de módulos y de config.
php artisan freescout:clear-cache
```

Después, abrir *System → Modules*: la tarjeta **RestApi** debe salir con el icono nuevo, versión 1.5.0
y la descripción completa. Si sigue el puzzle gris, ver el punto siguiente.

## Ojo: la carpeta `Modules/template`

En este repositorio hay una carpeta **sin versionar** (`Modules/template`, la ignora `.gitignore`)
con restos del generador de módulos. Su `module.json` declaraba **el mismo `name` y el mismo `alias`
que el módulo real**:

```json
"name": "RestApi",  "alias": "restapi",  "version": "1.0.0",  "providers": ["Modules\\RestApi\\…"]
```

`Nwidart\Modules\Repository::scan()` indexa los módulos por `name`:

```php
$modules[$name] = $this->createModule($this->app, $name, dirname($manifest));
```

Como `Modules/template/module.json` va **después** alfabéticamente, sobrescribía la entrada del módulo
real. Consecuencia: la tarjeta "RestApi" mostraba los datos de la carpeta de restos (versión **1.0.0**,
descripción vacía, sin `img`) en lugar de los del módulo real. Comprobado en local: con la carpeta
presente `getPath()` = `Modules/template` y `get('img')` = `""`.

**Qué hacer en el servidor si existe** (no está en git, así que hay que mirarlo a mano):

```bash
ls -l Modules/template/module.json
mv Modules/template/module.json Modules/template/module.json.disabled   # lo desactiva sin borrar nada
php artisan freescout:clear-cache
```

Renombrarlo basta: sin `module.json`, el escaneo salta la carpeta y deja de registrar su `start.php`
(que hacía `require` de un `Http/routes.php` con un `GET /` de andamiaje). Si se prefiere, se puede
borrar la carpeta entera: no la usa nada y la API real carga sus rutas desde
`Modules\RestApi\Providers\RestApiServiceProvider::boot()` → `loadRoutesFrom()`, no desde ahí.

## Comprobar

```bash
# ¿Qué módulo resuelve FreeScout?
php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app("modules")->clearCache(); foreach (app("modules")->all(true) as $n=>$m) { echo $n," alias=",$m->getAlias()," img=",$m->get("img")," ver=",$m->get("version")," ruta=",$m->getPath(),PHP_EOL; }'
```

Debe salir `RestApi alias=restapi img=/modules/restapi/img/icon.png ver=1.5.0 ruta=…/Modules/RestApi`.
