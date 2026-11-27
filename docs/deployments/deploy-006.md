# Paquete de despliegue 006 — RestApi 1.6.0 (thread_type, Kanban y assigned_to=null)

- **Paquete:** [deploy-006.tar.gz](../../artifacts/deployments/deploy-006.tar.gz).
- **Origen:** cambios de T0.5 en `Modules/RestApi`.
- **Versión:** `1.6.0` (antes `1.5.0`).
- **Contenido:** 14 ficheros, sin migraciones y sin secretos.
- **Prerrequisito:** módulo **Kanban 1.0.0** activo y migrado. Si no está activo,
  `kanban_stage` responde `422 kanban_not_available`, pero el resto de la API sigue funcionando.

```text
sha256  114DE7E7D2E1283F0D89D2CD1798EDC38421E6979527D5BF6EF2B0CD486F0C0D
```

| Fichero | Qué cambia |
|---|---|
| `Modules/RestApi/Support/Kanban.php` | Puente opcional con Kanban: etapas, validación y movimiento. |
| `Modules/RestApi/Http/Controllers/MailboxesController.php` | `GET /api/v1/mailboxes/{id}/kanban-stages`. |
| `Modules/RestApi/Http/Controllers/ConversationsController.php` | `thread_type`, `kanban_stage`, `assigned_to=null` y filtros. |
| `Modules/RestApi/Http/Controllers/ThreadsController.php` | Alias `thread_type` en hilos. |
| `Modules/RestApi/Http/Requests/*.php` | Validación de los campos nuevos; `assigned_to` nullable. |
| `Modules/RestApi/Entities/DTOs/ConversationDTO.php` | Expone `assigned_to` y `kanban_stage`. |
| `Modules/RestApi/Http/routes.php` | Ruta de etapas Kanban. |
| `Modules/RestApi/module.json` | Versión `1.6.0`. |
| `Modules/RestApi/CHANGELOG.md`, `README.md`, `INDEX.md` | Documentación. |
| `Modules/RestApi/Resources/docs/openapi.yaml` | Contrato actualizado. |

## Línea base observada (2026-10-28)

Antes de aplicar este paquete, el entorno real de `tickets.qsoftware.biz` respondió:

- `GET /api/v1/conversations`: `200`; token válido y buzón 1 visible.
- `GET /api/v1/mailboxes/1/kanban-stages`: `404` (ruta todavía no desplegada).
- `POST /api/v1/conversations` con `assigned_to: null`: `422`
  (`The assigned to must be an integer.`).
- No se creó ninguna conversación en esa prueba.

Eso confirma que la versión desplegada sigue siendo RestApi 1.5.0 y que este paquete
es necesario antes de repetir el spike T0.4.

## Aplicarlo

```bash
cd /var/www/vhosts/qsoftware.biz/tickets.qsoftware.biz

# Copia el paquete a /tmp y verifica el hash antes de extraer.
sha256sum /tmp/deploy-006.tar.gz

tar -xzf /tmp/deploy-006.tar.gz -C .
php artisan freescout:clear-cache
```

No hay migraciones nuevas de RestApi. La columna `conversations.kanban_stage` la aporta Kanban 1.0.0.

Si la ruta nueva sigue dando 404, limpia también la caché de rutas:

```bash
php artisan route:clear
php artisan config:clear
php artisan freescout:clear-cache
```

## Verificación

### 1. Etapas del buzón

```bash
curl -s -H "Authorization: Bearer $FS_TOKEN" \
  https://tickets.qsoftware.biz/api/v1/mailboxes/1/kanban-stages
```

Debe responder `200` con `data[0].id = "__none"` y las etapas configuradas del buzón.

### 2. Conversación con nota, sin asignar y en Sin etapa

```bash
curl -i -X POST https://tickets.qsoftware.biz/api/v1/conversations \
  -H "Authorization: Bearer $FS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "mailbox_id": 1,
    "channel": "email",
    "subject": "[FUNNEL-SPIKE] Verificación RestApi 1.6.0",
    "to": ["soporte@qsoftware.biz"],
    "name": "Funnel Spike",
    "body": "Prueba de despliegue. No responder.",
    "status": 1,
    "assigned_to": null,
    "send_message": false,
    "thread_type": 3,
    "kanban_stage": "__none"
  }'
```

Esperado: `201`; en la respuesta `assigned_to` y `kanban_stage` son `null`, y el primer hilo es de tipo `3` (nota).

### 3. Actualización de etapa

```bash
curl -i -X PUT https://tickets.qsoftware.biz/api/v1/conversations/<ID> \
  -H "Authorization: Bearer $FS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"assigned_to": null, "kanban_stage": "__none"}'
```

Esperado: `200`, sin reasignar al dueño del token.

## Rollback

El paquete no cambia el esquema. Para volver a 1.5.0, restaura los ficheros anteriores
(por ejemplo, desde el despliegue 005 o desde el commit base) y ejecuta:

```bash
php artisan freescout:clear-cache
```

## Nota de seguridad

El token de FreeScout que se usó para el spike quedó expuesto en un chat. Rótalo antes de
publicar el módulo:

```bash
php artisan restapi:list-tokens
php artisan restapi:revoke-token <TOKEN_ID>
php artisan restapi:create-token <USER_ID> "Funnel Creator" --mailbox_ids=1 --rate_limit=5000
```

No incluyas el token en el paquete, en `deploy-006.md` ni en el repositorio.
