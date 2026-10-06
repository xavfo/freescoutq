# Módulo Kanban

Tablero de conversaciones abiertas para FreeScout, con **etapas configurables por buzón** y
**arrastrar y soltar** para cambiar el estado de una conversación sin abrirla.

- Versión: 1.0.0
- Alias: `kanban`
- Autor: qsoftware.biz
- Módulo independiente: **no modifica ni un archivo del core** (todo se engancha por `Eventy`, rutas
  propias y una columna nueva en `conversations`).

---

## Qué resuelve

FreeScout organiza el trabajo por carpetas y estados (Activa / Pendiente / Cerrada). Eso vale para
trabajar una conversación, pero no para ver *en qué punto está cada cosa* de un vistazo. Este módulo
añade un tablero con columnas que define cada equipo (por ejemplo
`Nuevo → En curso → Esperando cliente → Resuelto`) y una tarjeta por conversación abierta.

Tres decisiones que explican el resto del diseño:

1. **Las etapas son por buzón, no globales.** Un buzón de soporte y uno de facturación no tienen el
   mismo flujo. Se guardan en `mailboxes.meta['kanban']['stages']`, igual que los ajustes de WhatsApp
   del core.
2. **La fase vive en una columna propia** (`conversations.kanban_stage`, indexada) y no en
   `conversations.meta`. `meta` es un `text` con JSON: contar y paginar por columna en SQL sería
   imposible y los contadores del tablero serían mentira con "todo lo abierto" en pantalla.
3. **El color de la línea izquierda de la tarjeta es el TIPO DE MEDIO** (correo, WhatsApp, teléfono…).
   La fase se identifica por el color de la columna. Así, de un vistazo, se distinguen las dos cosas
   sin leer nada: *qué es* (medio) y *en qué punto está* (fase).

---

## Instalación

Requiere FreeScout 1.8.x con PHP 7.4 - 8.1.

```bash
# 1) El namespace del módulo tiene que ser autoloadable.
#    En este repo YA está en el psr-4 de composer.json (raíz) y en el autoloader generado.
#    Si algún día se regenera el autoloader con Composer, se hace solo.

# 2) Activar el módulo desde la interfaz (System → Modules → Kanban → Activate),
#    que es lo que hace estos tres pasos:
#      · fila en la tabla `modules` (alias = kanban, active = 1)
#      · php artisan freescout:module-install kanban   (migra + crea el enlace de assets)
#      · php artisan migrate --force

# 3) Si se activa a mano (sin la interfaz):
php artisan freescout:module-install kanban
php artisan freescout:clear-cache
```

El comando `freescout:module-install` hace la migración y crea el enlace
`public/modules/kanban → Modules/Kanban/Public` (es lo que sirve el CSS y el JS). Sin ese enlace el
tablero funciona pero se ve sin estilos.

> Nota: `module.json` lleva `"active": 1`, pero **FreeScout ignora ese campo**: el módulo está activo
> sólo si su fila en la tabla `modules` tiene `active = 1`.

---

## Uso

- **Tablero**: entrada «Tablero» en la barra superior → `/kanban`.
- **Mover una conversación**: arrástrala a otra columna, o usa el menú `⋮` de la tarjeta →
  «Mover a “En curso”». Al soltar, la tarjeta se mueve ya (actualización optimista) y si el servidor la
  rechaza (sin permisos, fase inexistente…) vuelve a su sitio con un aviso.
- **Acciones rápidas** (menú `⋮`): asignármela, quitar asignado, activa, pendiente, cerrar, abrir.
  Reutilizan el endpoint del core, así que respetan permisos, historial y notificaciones.
- **Filtros**: buzón, asignado (incluye «sin asignar» y «asignados a mí»), búsqueda por asunto/cliente/
  correo y «solo sin respuesta».
- **Etapas**: botón «Etapas» (sólo con permiso `updateSettings` sobre el buzón) → `/kanban/settings`.

### Qué entra en el tablero

Conversaciones **publicadas** y **abiertas** (`state = published`, `status IN (1,2)` = activas y
pendientes) de **los buzones que el usuario puede ver**. Nada más. Si un usuario pide un buzón al que
no tiene acceso, el tablero responde `403`.

Una conversación sin fase (o cuya fase se borró del buzón) aparece en la columna virtual
**«Sin etapa»** (`__none`), que siempre existe y no se puede eliminar: es la red de seguridad para no
perder nada por el camino.

---

## Configuración

### Etapas por buzón (recomendado)

`/kanban/settings`. Cada etapa tiene:

| Campo | Para qué |
|---|---|
| `id` | Identificador estable. No lo cambies una vez en uso (las conversaciones lo referencian). |
| `name` | Nombre visible de la columna. |
| `color` | Color de la cabecera; el fondo de la columna es ese color aclarado. |
| `wip` | Límite de trabajo en curso. `0` = sin límite. Si se supera, el contador se pone rojo (sólo avisa, no bloquea). |
| `status` | Opcional. Si se define, mover aquí la conversación **también cambia su estado** (por ejemplo «Resuelto» → cerrada). Se hace con `changeStatus()` del core, así que queda en el historial. |

Al guardar, el módulo normaliza todo: descarta etapas sin nombre, ids repetidos, ids reservados
(`__none`), colores fuera de la paleta y `wip` negativos.

### Ajustes globales (`Modules/Kanban/Config/config.php`)

| Clave | Por defecto | Qué hace |
|---|---|---|
| `statuses` | `[1, 2]` | Estados de conversación que aparecen en el tablero. |
| `per_column` | `30` | Tarjetas por columna y por petición («Cargar más»). |
| `stale_days` | `3` | Días para marcar en rojo una tarjeta **si la última palabra fue del cliente**. |
| `refresh_seconds` | `0` | Refresco automático. `0` = sólo manual. Se desactiva sólo mientras arrastras, con un menú abierto o con la pestaña en segundo plano. |
| `unassigned_column` | Sin etapa | Nombre y color de la columna virtual. |
| `media` | — | Color de la línea izquierda por tipo de medio. |
| `stages` | 4 fases | Fases por defecto de un buzón que no ha configurado nada. |
| `palette` | 12 colores | Colores permitidos al editar etapas. |

---

## Cómo funciona por dentro

### Rutas

Todas bajo el prefijo `/kanban` y con los middleware `web` y `auth`.

| Método | Ruta | Nombre | Qué hace |
|---|---|---|---|
| GET | `/kanban` | `kanban.index` | Tablero (HTML con las tarjetas ya pintadas por el servidor). |
| POST | `/kanban/board` | `kanban.board` | Refresco y «cargar más». Devuelve JSON con el HTML de cada columna. |
| POST | `/kanban/move` | `kanban.move` | Mueve una conversación a otra etapa. |
| GET/POST | `/kanban/settings` | `kanban.settings` / `kanban.settings.save` | Editor de etapas por buzón. |

`POST /kanban/move` responde `{status, msg, conversation_id, stage, status_changed, counts}` y falla con
`404` (no existe), `403` (sin permiso) o `422` (la etapa no pertenece al buzón de la conversación).

### Una sola fuente de marcado

El servidor pinta las tarjetas (`partials/card` desde `partials/cards`) y `POST /kanban/board` devuelve
ese mismo HTML ya renderizado. El JavaScript **nunca construye tarjetas**: sólo lo sustituye. Así no hay
dos versiones del mismo marcado que se puedan desincronizar.

### Datos

- `conversations.kanban_stage` (`varchar(64)`, indexada, nullable): la fase actual.
- `conversations.meta['kanban_stage_at']` / `['kanban_stage_by']`: cuándo y quién la movió.
- `mailboxes.meta['kanban']['stages']`: las etapas del buzón.

### Rendimiento

Una consulta agregada para los contadores de todas las columnas + una consulta por columna con
`limit/offset` (nunca se traen todas las conversaciones para contarlas). Las relaciones que usa la
tarjeta se cargan con `with(['customer:id,...', 'user:id,...', 'mailbox:id,name'])`.

---

## Estructura

```
Modules/Kanban/
├── module.json                 metadatos y proveedor
├── start.php                   vacío a propósito (las rutas las carga el proveedor)
├── composer.json               psr-4 del módulo
├── Config/config.php           ajustes globales
├── Providers/KanbanServiceProvider.php
├── Http/
│   ├── routes.php
│   └── Controllers/KanbanController.php
├── Entities/
│   ├── Board.php               consultas del tablero (columnas, contadores, filtros)
│   └── DTOs/CardDTO.php        una tarjeta (medio, antigüedad, avatar…)
├── Support/
│   ├── Stages.php              fases: leer/validar/guardar/mover + tinte de color
│   └── Medium.php              tipo de medio y su color
├── Database/Migrations/        conversations.kanban_stage
├── Resources/views/            index, settings, no-mailboxes y partials
├── Resources/lang/{es,en}/     textos
└── Public/                     css/, js/ y img/icon.png (se sirven por el enlace /modules/kanban)
```

El icono de la tarjeta del módulo (`Public/img/icon.png`, 256×256 con esquinas transparentes) se
genera con `php tools/make-module-icons.php`, que rehace también el de RestApi. No necesita GD:
dibuja con distancias con signo y escribe el PNG con zlib. Es determinista, así que reejecutarlo no
cambia los ficheros. La ruta que muestra la tarjeta sale de la clave `img` de `module.json`.

---

## Detalles de integración con FreeScout (para no romperlos al tocar el módulo)

- El item del menú se añade con `Eventy::addAction('menu.append')`, y para que se marque como activo
  hay que registrar la ruta en `Helper::$menu` con el filtro `menu.selected`.
- **Nada de `whereHas` / `orWhereHas` / `whereExists`** en las consultas: el `Query\Builder`
  sobrescrito por FreeScout lanza `compact(): Undefined variable $operator`. Se usan subconsultas
  (`whereIn('customer_id', ...)`).
- `Conversation::statusToName()` **no existe**; el método correcto es `Conversation::statusCodeToName()`
  (estático) o `getStatusName()` (instancia).
- Los estados de una conversación se cambian con `changeStatus()`, que además guarda y registra el
  cambio en el historial. El módulo no escribe `status` a mano.
- `conversations.channel` existe pero FreeScout no la rellena (siempre `NULL`); el dato fiable del medio
  es `conversations.type`. Si algún módulo sí la rellena, se respeta su nombre (`getChannelName()`).

---

## 1.0.0

- Tablero con etapas configurables por buzón, columna «Sin etapa» y contadores con aviso de WIP.
- Arrastrar y soltar con actualización optimista y reversión exacta si el servidor rechaza el cambio.
- Menú «Mover a» como alternativa al arrastre (teclado).
- Acciones rápidas por tarjeta reutilizando `conversations.ajax` del core.
- Filtros por buzón, asignado, búsqueda y «sin respuesta».
- Editor de etapas por buzón con restauración de las de por defecto.
- Traducciones en español e inglés.
