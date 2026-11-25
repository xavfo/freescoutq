# Análisis del proyecto `freescoutq`

Documento de referencia del estado actual del repositorio: qué es, qué hace,
qué integraciones tiene y qué queda pendiente. Generado el 2026-11-24.

---

## 1. Qué es este proyecto

Un **fork/customización de FreeScout 1.8.x** (help desk self-hosted, PHP/Laravel,
MySQL/PostgreSQL) orientado a producción en **`https://tickets.qsoftware.biz`**.
No es un proyecto nuevo: el grueso del código es el core de FreeScout y encima
hay trabajo propio en tres frentes:

| Frente | Dónde vive | Estado |
|---|---|---|
| **Módulo REST API** (`restapi`) | `Modules/RestApi/` | v1.6.0 — producción |
| **Módulo Kanban** (`kanban`) | `Modules/Kanban/` | v1.0.0 — producción |
| **WhatsApp vía Evolution API** | `app/` (core modificado) | Implementado, entrega saliente |
| **Integración QChat** | `docs/qchat-integration.md` + API | Consumidor externo de la API |

Regla de diseño repetida en ambos módulos: **cero cambios en el core de
FreeScout** dentro de `Modules/`, para poder actualizar el upstream sin
conflictos. La excepción es la integración de WhatsApp, que sí toca `app/`
(`Mailbox`, `Conversation`, controladores, jobs, listeners).

---

## 2. Funciones

### 2.1 Core FreeScout (heredado)

Help desk completo: buzones compartidos, conversaciones por email, carpetas,
estados (activa/pendiente/cerrada), notas internas, asignación, búsqueda,
notificaciones, multilingüe, apps móviles, instalador web, etc. Requisitos:
PHP 7.1–8.x, MySQL/MariaDB/PostgreSQL.

### 2.2 Módulo REST API — `Modules/RestApi` (v1.6.0)

API REST propia bajo `/api/v1` con autenticación Bearer, rate limiting,
auditoría y webhooks. Detalle en `Modules/RestApi/INDEX.md` y
`Modules/RestApi/README.md`.

**Endpoints** (`Modules/RestApi/Http/routes.php`):

- **Conversations**: `GET/POST /conversations`, `GET/PUT/DELETE /conversations/{id}`
  - Filtros: `status`, `customer_id`, `mailbox_id`, `assigned_to` (incluye `null`=sin asignar),
    `kanban_stage` (incluye `__none`), `sort`, paginación.
  - `POST` acepta `thread_type` (2=mensaje, 3=nota, 8=chat), `kanban_stage`,
    `assigned_to: null`, `send_message`/`send`.
- **Threads**: CRUD completo + `POST /conversations/{id}/threads`.
- **Customers**: `GET/POST /customers`, `GET/PUT/DELETE /customers/{id}`
  (búsqueda por nombre/email; `409` si el email existe o el cliente tiene conversaciones).
- **Mailboxes**: `GET /mailboxes/{id}/kanban-stages`.

**Canales (`Support/Channels.php`)**: `email`(1), `phone`(2), `chat`(3),
`custom`(4), `whatsapp`(5). Se acepta `type` numérico o `channel` por nombre.
Solo `email` y `whatsapp` entregan mensajes reales; `phone`/`chat`/`custom`
solo registran.

**Entrega**: `send_message=true` (o por defecto en WhatsApp) encola en la cola
`emails`: `SendReplyToCustomer` (SMTP) o `SendWhatsappReply` (Evolution API).
Requiere `php artisan queue:work` activo.

**Seguridad**: tokens `fs_…` con hash SHA-256, restricción por buzón
(`mailbox_ids`), expiración opcional, rate limit configurable (1000/h por
defecto), tabla `api_audit_logs`, webhooks salientes con firma HMAC-SHA256 y
reintentos. **Gestión de tokens por UI web** ya implementada:
`/restapi/tokens` (solo admins, `ApiTokenController` + vistas `restapi::apitokens`).

**Tablas propias**: `api_keys`, `api_audit_logs`, `api_webhooks`,
`api_rate_limits` (+ `conversation_type` en `api_keys` de una migración previa,
 hoy ignorado).

### 2.3 Módulo Kanban — `Modules/Kanban` (v1.0.0)

Tablero drag&drop de conversaciones abiertas (`/kanban`), con etapas
**configurables por buzón** (guardadas en `mailboxes.meta['kanban']['stages']`).

- Columnas por etapa + columna virtual **«Sin etapa»** (`__none`).
- Tarjeta = conversación; color de línea izquierda = **tipo de medio**
  (`Support/Medium.php`), color de columna = fase.
- WIP limits (solo avisan), filtros (buzón, asignado, búsqueda, sin respuesta),
  acciones rápidas reutilizando `conversations.ajax` del core.
- Datos: `conversations.kanban_stage` (varchar indexado) +
  `meta['kanban_stage_at'/'_by']`.
- Etapa puede forzar cambio de `status` vía `changeStatus()` del core.
- Rutas: `GET /kanban`, `POST /kanban/board`, `POST /kanban/move`,
  `GET/POST /kanban/settings`. i18n es/en.

### 2.4 WhatsApp / Evolution API (core modificado)

- `app/Misc/EvolutionApi.php`: cliente Guzzle mínimo (`sendText`, `sendMedia`,
  `connectionState`), credenciales por buzón.
- `app/Mailbox.php`: `getWhatsappSettings()/setWhatsappSettings()` en
  `mailboxes.meta['whatsapp']` con **API key cifrada**;
  `isWhatsappEnabled()`.
- `app/Jobs/SendWhatsappReply.php`: entrega asíncrona (cola `emails`, 3
  reintentos, release 300s), HTML→texto, estados `send_status`
  (pending/accepted/send_error/intermediate_error), **adjuntos no se envían**
  (solo avisa en el log).
- `app/Listeners/SendWhatsappReply.php` + enganches en
  `ConversationsController`, `MailboxesController`, vistas
  (`mailboxes/update`, `conversations/create`) y `Conversation::isWhatsapp()`.
- UI de configuración: **Ajustes del buzón → WhatsApp** (URL base, instancia,
  API key).

---

## 3. Integraciones

| Integración | Dirección | Estado |
|---|---|---|
| **QChat** (`docs/qchat-integration.md`) | QChat → API `/api/v1` | Documentada; crea conversaciones/hilos por todos los canales |
| **Evolution API** (WhatsApp) | FreeScout → Evolution | Saliente implementado (`sendText`); **entrantes no** |
| **SMTP** (correo saliente por buzón) | FreeScout → SMTP | Heredado del core |
| **Webhooks salientes** | FreeScout → URL externa | Implementados (HMAC-SHA256, reintentos) |
| **Zapier / Make (Integromat)** | Vía API/core | Heredados del ecosistema FreeScout |
| **Apps móviles / MacOS** | Core | Heredados |

---

## 4. Despliegue

- Producción: `/var/www/vhosts/qsoftware.biz/tickets.qsoftware.biz`.
- Los `deploy-00N.md` + `.tar.gz` son **paquetes de despliegue manuales**
  (hash sha256, instrucciones de extracción, `freescout:clear-cache`,
  verificación y rollback). Historial:
  - `002`: módulo Kanban + arreglos de despliegue (base `1.8.208`).
  - `003`: fix urgente de minificación CSS / Whoops roto.
  - `004`/`005`: iconos de módulos RestApi/Kanban + enlaces `public/modules/*`.
  - `006`: RestApi 1.6.0 (`thread_type`, `kanban_stage`, `assigned_to=null`).
- `restapi-1.6.0.zip` y `docs/openapi.yaml` (OpenAPI 3.0) como artefactos.
- **Pendiente de seguridad**: un token de FreeScout usado en pruebas quedó
  expuesto en un chat → **rotarlo** (`restapi:revoke-token` /
  `restapi:create-token`).

---

## 5. Pendientes de desarrollo

### 5.1 Explícitos en el código/docs

- **Webhooks entrantes de Evolution API** (`messages.upsert`): no existe endpoint
  público; hoy QChat debe crear conversaciones/hilos vía API
  (`docs/qchat-integration.md` §8).
- **Adjuntos por WhatsApp**: `sendMedia` existe en `EvolutionApi` pero el job
  solo envía texto; los adjuntos se avisan como no enviados.
- **`send_logs`**: los envíos de WhatsApp se registran como tipo email
  (`MAIL_TYPE_EMAIL_TO_CUSTOMER`); en API se distinguen por `channel`/`send_status`.
- **RestApi — roadmap** (`CHANGELOG.md` / `INDEX.md`):
  - v1.1: Swagger UI endpoint, Postman export, GraphQL, batch ops,
    **file attachments via API**, search endpoint.
  - v1.2: OAuth 2.0, custom fields, bulk email, templates.
  - (La UI de tokens ya existe — `/restapi/tokens` — aunque `INDEX.md` aún la
    lista como pendiente; conviene actualizar ese checklist.)
- **Kanban**: v1.0.0 completo; sin roadmap declarado.

### 5.2 Deuda técnica / observaciones

- `docs/qchat-integration.md` y `Modules/RestApi/Resources/docs/openapi.yaml`
  pueden divergir del `docs/openapi.yaml` raíz — mantener sincronizados.
- `.env` local contiene credenciales reales (gitignored); los `deploy-*.md`
  referencian rutas y usuarios del servidor (`xavfo_whatsapp:psacln`).
- `Modules/RestApi/INDEX.md` declara versión 1.5.0 y checklist desactualizado
  respecto a 1.6.0.
- Tests: `Modules/RestApi/Tests` (Feature/Unit) y `Modules/Kanban/Tests`;
  ejecutar con `php artisan test --filter=RestApi|Kanban`.
- `composer.json` raíz incluye psr-4 de los módulos: regenerar el autoloader
  con Composer es seguro, pero hay que vigilarlo en despliegues.

---

## 6. Mapa rápido de archivos clave

```
app/Misc/EvolutionApi.php          Cliente Evolution API
app/Jobs/SendWhatsappReply.php     Entrega WhatsApp (cola)
app/Mailbox.php                    Ajustes WhatsApp por buzón (meta cifrada)
app/Conversation.php               isWhatsapp(), tipos de conversación
Modules/RestApi/                   API REST v1.6.0
  Http/routes.php                  Rutas /api/v1 + /restapi/tokens
  Support/Channels.php             Canales, validación y encolado
  Support/Kanban.php               Puente con módulo Kanban
  Http/Controllers/ApiTokenController.php  UI admin de tokens
Modules/Kanban/                    Tablero Kanban v1.0.0
  Entities/Board.php               Consultas del tablero
  Support/Stages.php, Medium.php   Etapas y tipo de medio
  Http/routes.php                  /kanban/*
docs/qchat-integration.md          Guía de integración QChat
docs/openapi.yaml                  Contrato OpenAPI 3.0
deploy-00N.md/.tar.gz              Paquetes de despliegue versionados
```
