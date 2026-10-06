# Módulo Kanban — tablero de conversaciones abiertas

## Objetivo

Añadir a FreeScout una vista **tablero Kanban** (columnas con tarjetas arrastrables) para gestionar
las conversaciones **activas y pendientes** de los buzones visibles por el usuario.

## Decisiones acordadas

| # | Decisión | Elección |
| --- | --- | --- |
| D1 | Qué representan las columnas | **Etapas propias configurables por buzón** ("Nuevo → En curso → Esperando cliente → Resuelto"), más una columna virtual **"Sin etapa"**. |
| D2 | Qué conversaciones entran | **Todo lo abierto**: `status IN (ACTIVE=1, PENDING=2)` y `state = PUBLISHED`. Filtros por buzón, asignado, antigüedad y búsqueda. |
| D3 | Acciones | **Arrastrar** entre columnas (cambia de etapa) **+ acciones rápidas**: asignar, cerrar/abrir, nota rápida, abrir conversación. |

## Principios de diseño

1. **No tocar el core.** Todo el módulo vive en `Modules/Kanban`. Las únicas escrituras son a través
   de métodos existentes (`Conversation::changeUser()`, `changeStatus()`, `Thread::create()`) y de
   una columna propia que añade el módulo con su migración.
2. **Sin dependencias nuevas.** Se reutiliza lo que ya trae FreeScout: `public/js/html5sortable.js`,
   `fsAjax()`, `laroute`, jQuery y Bootstrap 3.
3. **Mínimo conflicto con futuras actualizaciones de FreeScout.** Cero cambios en vistas o
   controladores del core (a diferencia del bloque WhatsApp en Ajustes del buzón). La configuración
   de etapas va en una **página propia del módulo**.
4. **Permisos en serio.** Toda consulta se acota a `Auth::user()->mailboxesCanView()`; todo cambio
   exige `$user->can('update', $conversation)`.

## Modelo de datos

### Etapas (configuración, por buzón)

Se guarda en el meta del buzón, igual que se hizo con WhatsApp:

```php
$mailbox->setMetaParam('kanban', [
    'enabled' => true,
    'stages'  => [
        ['id' => 'new',        'name' => 'Nuevo',             'color' => '#3498db', 'wip' => 0, 'status' => null],
        ['id' => 'in_progress','name' => 'En curso',          'color' => '#f39c12', 'wip' => 0, 'status' => null],
        ['id' => 'waiting',    'name' => 'Esperando cliente', 'color' => '#9b59b6', 'wip' => 0, 'status' => null],
        ['id' => 'resolved',   'name' => 'Resuelto',          'color' => '#27ae60', 'wip' => 0, 'status' => 3],
    ],
]);
```

- `id`: identificador estable (slug). No cambia al renombrar una etapa.
- `color`: color de la cabecera.
- `wip`: límite de trabajo en curso (0 = sin límite). Solo avisa visualmente.
- `status`: opcional. Si se define, mover una tarjeta a esa etapa **también** cambia el estado de
  la conversación (p. ej. "Resuelto" → cerrada). Es lo que hace útil el tablero para cerrar trabajo.
- Si el buzón no tiene configuración, se usan las **etapas por defecto** de arriba (el tablero
  funciona el primer día).

### Etapa de cada conversación

`conversations.meta` existe desde la migración `2022_12_17_010101` y `Conversation` ya lo castea a
array (`Conversation::$casts['meta'] = 'array'`, `getMeta()/setMeta()`).

**Decisión recomendada: columna propia indexada** (módulo, 1 migración):

```
conversations.kanban_stage  varchar(64) NULL, INDEX (kanban_stage)
```

Motivo: `meta` es `text` con JSON, así que **no se puede consultar ni paginar por etapa en SQL**;
con "todo lo abierto" puede haber miles de conversaciones y agrupar en PHP daría paginación
aproximada y contadores falsos. Con la columna los contadores y la paginación por columna son
exactos. Se guarda además la traza en `meta`:

```
conversations.meta['kanban_stage_at'] = '2026-10-05 12:34:56'
conversations.meta['kanban_stage_by'] = <user_id>
```

*Alternativa sin migración*: guardar solo en `meta['kanban_stage']` y agrupar en PHP con un límite
por columna. Válido para volúmenes pequeños; documentado por si se prefiere cero cambios de esquema.

## Estructura de archivos

```
Modules/Kanban/
├── module.json                          # name/alias/version/providers  (fuente única de versión)
├── start.php                            # VACÍO (solo comentario) ← evita rutas duplicadas + CSRF
├── composer.json                        # psr-4: "Modules\\Kanban\\": ""
├── Config/config.php                    # estados incluidos, límite por columna, etapas por defecto
├── Providers/KanbanServiceProvider.php  # loadRoutesFrom, vistas, traducciones, menú, moduleVersion()
├── Http/
│   ├── routes.php                       # /kanban · /kanban/board · /kanban/move · /kanban/settings
│   └── Controllers/KanbanController.php
├── Entities/
│   ├── Board.php                        # construye columnas y tarjetas (query + permisos + contadores)
│   └── DTOs/CardDTO.php
├── Support/
│   ├── Stages.php                       # leer/validar/guardar etapas del buzón + etapas por defecto
│   └── Scope.php                        # buzones visibles + can('update')
├── Database/Migrations/
│   └── 2026_10_05_000001_add_kanban_stage_to_conversations_table.php
├── Resources/
│   ├── views/kanban/{index.blade.php, settings.blade.php, partials/{column,card,filters}.blade.php}
│   └── lang/{es,en}/kanban.php
├── Public/{css/kanban.css, js/kanban.js}
└── Tests/{Unit/BoardTest.php, Feature/KanbanApiTest.php}
```

## Rutas

Middleware `web` + `auth`. **Registradas desde el ServiceProvider** (`loadRoutesFrom`), nunca desde
`start.php`.

| Método | Ruta | Respuesta | Descripción |
| --- | --- | --- | --- |
| `GET` | `/kanban` | HTML | Tablero (extiende `layouts.app`) |
| `POST` | `/kanban/board` | JSON | Columnas, contadores y tarjetas (filtros + paginación por columna) |
| `POST` | `/kanban/move` | JSON | Cambia la etapa de una conversación (drag & drop) |
| `GET` | `/kanban/settings` | HTML | Editor de etapas (solo quien pueda `updateSettings` del buzón) |
| `POST` | `/kanban/settings` | JSON/redirect | Guarda las etapas del buzón |

Las **acciones rápidas** (asignar, cerrar/abrir, nota) **no** crean endpoints nuevos: el JS del
módulo llama al endpoint del core `conversations.ajax` (`POST /conversation/ajax`) con
`action=conversation_change_user` / `conversation_change_status` / `send_reply&is_note=1`, que ya
valida permisos, crea el *line item*, notifica y respeta el resto de reglas del producto. Así no se
duplica lógica ni se pierden efectos secundarios.

## Contrato de `/kanban/board`

```json
{
  "columns": [
    { "id": "__none", "name": "Sin etapa", "color": "#95a5a6", "wip": 0, "count": 3,
      "cards": [ { "id": 123, "number": 456, "subject": "…", "customer": "…",
                   "age": "hace 3 h", "last_reply": "hace 20 min", "assignee": {"id": 2, "name": "…"},
                   "status": 2, "has_attachments": false, "url": "/conversation/123" } ] },
    { "id": "new", "name": "Nuevo", "color": "#3498db", "wip": 0, "count": 12, "cards": [ … ] }
  ],
  "meta": { "total": 57, "per_column": 30, "truncated": { "new": true } }
}
```

## Contrato de `/kanban/move`

```json
// petición
{ "conversation_id": 123, "stage": "in_progress" }
// 200
{ "status": "success", "msg": "Etapa actualizada", "conversation_id": 123, "stage": "in_progress", "status_changed": false }
// 403  -> sin permiso sobre la conversación
// 422  -> etapa desconocida para ese buzón / cambio inválido
```

## Consulta y rendimiento

- Base: `state = STATE_PUBLISHED`, `status IN (1,2)`, `whereIn('mailbox_id', $visibles)`.
- Filtros: `mailbox_id`, `user_id` (asignado), `sin asignar`, antigüedad (`last_reply_at`/`created_at`),
  búsqueda por asunto o cliente.
- **Una consulta por columna** con `limit` + `offset` (paginación real e "cargar más" por columna),
  y una consulta agregada para los contadores.
- `select` explícito (nada de `SELECT *`) y `with(['customer:id,first_name,last_name'])`.
- **Prohibido `whereHas`/`orWhereHas`/`whereExists`**: el `Query\Builder` sobrescrito por este
  FreeScout lanza `compact(): Undefined variable $operator`. Se usan subconsultas `whereIn`.
- Orden por defecto: `last_reply_at` (o `created_at`) descendente.

## UI

- **Cabecera**: filtros (buzón si hay más de uno, asignado, antigüedad, búsqueda, "mis conversaciones"),
  botón refrescar, total, y engranaje hacia la configuración de etapas (solo si puede).
- **Columna**: nombre con color, contador, aviso si supera el WIP, zona de drop.
- **Tarjeta**: `#número`, asunto, cliente, antigüedad, última respuesta, clip si hay adjuntos,
  asignado, y un menú de **acciones rápidas** (asignar, cerrar/abrir, nota rápida, abrir).
- **Drag & drop**: `html5sortable` por columna. Al soltar: actualización **optimista** + `fsAjax` a
  `/kanban/move`; si el servidor falla, se revierte la tarjeta a su columna y se muestra el mensaje.
- **Responsive**: scroll horizontal de columnas; en móvil, las columnas se apilan.
- **Assets**: `@section('stylesheets')` y `@section('javascript')` (el layout no tiene `@stack`).

## Permisos

| Acción | Comprobación |
| --- | --- |
| Ver el tablero | Buzones de `Auth::user()->mailboxesCanView()`; si el usuario no ve ningún buzón → 403 |
| Mover / acciones rápidas | `Auth::user()->can('update', $conversation)` → si no, 403 |
| Editar etapas | `Auth::user()->can('updateSettings', $mailbox)` (o admin) |

## Integración con el menú

Item nuevo en la barra superior con `@action('menu.append')`, resaltado con
`Helper::menuSelectedHtml('kanban')`, enlazando a `route('kanban.index')`.

## Trampas conocidas de este repo (ya nos han mordido)

1. `Modules/<X>/start.php` debe quedar **vacío**: si carga las rutas, se registran dentro del grupo
   `web` y con CSRF → rutas duplicadas y respuestas vacías.
2. `whereHas`/`orWhereHas`/`whereExists` **rotos** → `whereIn` con subconsultas.
3. **CRLF**: los archivos nuevos deben usar el mismo fin de línea que el repositorio para no
   generar diffs gigantes.
4. `composer.json` (raíz) hay que añadir `"Modules\\Kanban\\": "Modules/Kanban/"` a `psr-4` y
   ejecutar `composer dump-autoload --no-dev --optimize --no-scripts`. **Nunca sin `--no-scripts`**:
   los scripts de Composer borran archivos listados en `exclude-from-classmap`.
5. El módulo necesita su fila en la tabla `modules` (`alias='kanban'`, `active=1`) para cargarse.
6. **Nunca subir `vendor/` por scp** al desplegar: el autoloader es específico de cada instalación.
7. `Conversation` y `Thread` tienen *mass assignment* restringido: usar `Thread::create(...)`, no
   `new Thread([...])`.

## Fuera de alcance (v1)

- Sincronización en vivo (websockets). Se refresca a mano o por intervalo configurable.
- Etapas por usuario (las etapas son por buzón).
- Métricas históricas de ciclo de tiempo por etapa (posible fase futura con los *line items*).
