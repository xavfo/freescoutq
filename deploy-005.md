# Paquete de despliegue 005 — imagen del módulo Kanban + arreglo de los enlaces

- **Origen**: commits `da4ceeb8` («Imagen representativa para el modulo Kanban») y `b27b9efa`
  («Guardar el generador de los iconos de modulo», que regenera este PNG).
- **Contenido**: 2 ficheros, ~5,8 KB. **Aditivo**: se extrae igual que el 004, sin solaparse.
- Si aún no se ha aplicado el **004**, aplicarlo también: trae el icono de RestApi y la clave `img`
  del `module.json` de ese módulo.

```bash
sha256  4BA24830B8E5B565426971CF1C57838A191DBBE3608CF93B7C68FE5F51724DEE
```

> Regenerado el 2026-10-06: el generador `tools/make-module-icons.php` compone a 8 bits y el PNG de
> Kanban cambió en 3468 píxeles de 65536 en ±1 (redondeo del antialiasing, invisible). Este paquete
> lleva ya los bytes definitivos. El **004 no cambia**.

| Fichero | Qué es |
|---|---|
| `Modules/Kanban/module.json` | Nueva clave `"img": "/modules/kanban/img/icon.png"`. |
| `Modules/Kanban/Public/img/icon.png` | Tablero de tres columnas con tarjetas 3/2/1, 256×256 RGBA con esquinas transparentes. |

El icono se puede regenerar (o retocar) con `php tools/make-module-icons.php`, que rehace también
el de RestApi. No hace falta GD: dibuja con distancias con signo y escribe el PNG con zlib. Es
determinista, así que reejecutarlo no cambia los ficheros.

## Lo que hacía que la imagen de RestApi no apareciera

La clave `img` no contiene una ruta de fichero: es una **URL**. `/modules/restapi/img/icon.png` sólo
existe si existe el enlace `public/modules/restapi` → `Modules/RestApi/Public`, que FreeScout crea al
instalar el módulo. Si el enlace falta, el fichero está en el módulo pero el navegador recibe un 404 y
la tarjeta pinta el icono de imagen rota.

Pasaba en dos sitios por motivos distintos:

- **Windows local**: `symlink()` no funciona sin permisos (modo desarrollador o administrador), así que
  `freescout:module-install` no podía crear el enlace. Solución aplicada: una *junction* de Windows,
  que no requiere permisos:
  ```powershell
  cmd /c mklink /J "public\modules\restapi" "Modules\RestApi\Public"
  cmd /c mklink /J "public\modules\kanban"  "Modules\Kanban\Public"
  ```
  (de paso arregla `kanban.css` y `kanban.js`, que tampoco se servían). `public/modules` está en
  `.gitignore`, así que no ensucia el repositorio.
- **Servidor Linux**: si el enlace se creó mientras existía la carpeta de restos `Modules/template`
  (ver `deploy-004.md`), apunta a `Modules/template/Public`, que está vacía. `checkSymlinks()` sólo
  crea los enlaces que **no existen**, no corrige los que apuntan mal, así que hay que borrarlo:
  ```bash
  rm -f public/modules/restapi public/modules/kanban
  php artisan freescout:module-install restapi kanban
  ```

## Aplicarlo

```bash
cd /var/www/vhosts/qsoftware.biz/tickets.qsoftware.biz
tar -xzf /tmp/deploy-005.tar.gz -C .          # y el 004 si no se aplicó

ls -l public/modules/restapi public/modules/kanban
# si falta alguno o apunta a Modules/template/Public:
rm -f public/modules/restapi public/modules/kanban
php artisan freescout:module-install restapi
php artisan freescout:module-install kanban

php artisan freescout:clear-cache
```

Comprobar que el servidor sirve los ficheros (debe responder `200` y `image/png`):

```bash
curl -sI https://tickets.qsoftware.biz/modules/restapi/img/icon.png | head -3
curl -sI https://tickets.qsoftware.biz/modules/kanban/img/icon.png  | head -3
```

Y que el módulo resuelto es el correcto (no la carpeta de restos):

```bash
php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app("modules")->clearCache(); foreach (app("modules")->all(true) as $n=>$m) { echo $n," ver=",$m->get("version")," img=",$m->get("img")," ruta=",$m->getPath(),PHP_EOL; }'
```

Salida esperada:

```
Kanban  ver=1.0.0 img=/modules/kanban/img/icon.png   ruta=…/Modules/Kanban
RestApi ver=1.5.0 img=/modules/restapi/img/icon.png ruta=…/Modules/RestApi
```
