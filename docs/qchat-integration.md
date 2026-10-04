# Integración de QChat con la API de FreeScout (módulo `RestApi`)

Guía para conectar **QChat** con este FreeScout. Cubre autenticación, tipos de medio
(email, teléfono, chat, personalizado y **WhatsApp**), entrega real de mensajes,
errores y ejemplos listos para copiar.

- **Base URL:** `https://tickets.qsoftware.biz/api/v1`
- **Formato:** JSON (`Content-Type: application/json`, `Accept: application/json`)
- **Especificación:** [`docs/openapi.yaml`](./openapi.yaml) (OpenAPI 3.0)

---

## 1. Autenticación

Todos los endpoints (salvo la gestión de tokens, que es por web) requieren un **token de API**
enviado como `Bearer`:

```http
Authorization: Bearer fs_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

### ¿Dónde se obtiene el token?

En la interfaz web: **Ajustes → API → Administrar Tokens de API** (solo administradores).

> **Los tokens NO están restringidos por medio de comunicación.**
> Un mismo token puede listar y crear conversaciones de email, teléfono, chat, personalizado y
> WhatsApp. La única restricción disponible es **por buzón** (`mailbox_ids`); si se deja vacío,
> el token accede a todos los buzones.

### Ciclo de vida

| Propiedad | Descripción |
| --- | --- |
| `mailbox_ids` | Lista de buzones permitidos. `null`/vacío = todos. |
| `rate_limit` | Peticiones máximas por ventana (por defecto `1000/hora`). |
| `expires_at` | Caducidad opcional. Si caduca, la API responde `401`. |

### Rate limiting

Al superar el límite la respuesta es `429` con cabecera `Retry-After`:

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 1234
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1730000000
```

```json
{ "message": "Rate limit exceeded. Retry after 1234 seconds", "status_code": 429, "retry_after": 1234 }
```

---

## 2. Tipos de medio (channels)

`channel` es el nombre legible del medio; `type` es el número que usa FreeScout.
En las peticiones se puede enviar **cualquiera de los dos** (`channel` gana si se envían ambos).

| `channel` | `type` | Identifica al destinatario por | ¿Entrega mensaje? |
| --- | --- | --- | --- |
| `email` | `1` | email (`to[]`) | Sí (correo saliente) |
| `phone` | `2` | teléfono (`phone`) | No |
| `chat` | `3` | — | No |
| `custom` | `4` | — | No |
| `whatsapp` | `5` | teléfono (`phone`) | **Sí (Evolution API)** |

Todas las respuestas de conversación incluyen `type`, `type_name` y `channel`; las de hilo
incluyen `send_status` y `send_status_name`.

---

## 3. Requisitos de configuración por buzón

La API **falla rápido** con un error claro cuando el buzón no puede entregar por el medio pedido.
Para WhatsApp o email:

| Medio | Requisito | Error si falta |
| --- | --- | --- |
| WhatsApp | **Ajustes del buzón → WhatsApp** activado con URL base, instancia y API key de Evolution API | `422 whatsapp_not_configured` |
| Email | Salida del buzón configurada (**Ajustes del buzón → Conexión saliente**) | `422 email_not_configured` |

Los medios `phone`, `chat` y `custom` no requieren configuración de entrega.

---

## 4. `send_message`: crear vs. entregar

Al crear una conversación o un hilo se puede indicar si el mensaje debe **entregarse al cliente**:

| Valor | Comportamiento |
| --- | --- |
| *(omitido)* en WhatsApp | **Se entrega** (es el propósito del tipo). |
| *(omitido)* en cualquier otro medio | **No se entrega** (solo se crea). |
| `true` | Se valida la configuración del buzón y se encola la entrega. |
| `false` | Solo se crea el registro; nunca se entrega. |

`send` se acepta como alias de `send_message`.
La entrega se hace por la **cola `emails`**, por lo que el worker de FreeScout
(`php artisan queue:work`) debe estar corriendo.

---

## 5. Endpoints

### 5.1 Conversaciones

| Método | Ruta | Descripción |
| --- | --- | --- |
| `GET` | `/conversations` | Lista paginada. Filtros: `status`, `customer_id`, `mailbox_id`, `assigned_to`, `sort`, `page`, `per_page`. |
| `POST` | `/conversations` | Crea una conversación de **cualquier medio**. |
| `GET` | `/conversations/{id}` | Detalle. |
| `PUT` | `/conversations/{id}` | Actualiza `status`, `assigned_to`, `customer_id`. |
| `DELETE` | `/conversations/{id}` | Borrado lógico (`state = deleted`). |

#### Crear conversación de WhatsApp

```bash
curl -X POST https://tickets.qsoftware.biz/api/v1/conversations \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "mailbox_id": 3,
    "channel": "whatsapp",
    "subject": "Consulta por WhatsApp",
    "phone": "+34 600 11 22 33",
    "name": "Jane Doe",
    "body": "Hola, ¿en qué podemos ayudarte?"
  }'
```

`201 Created`:

```json
{
  "id": 1234,
  "mailbox_id": 3,
  "subject": "Consulta por WhatsApp",
  "customer": { "name": "Jane Doe", "email": "" },
  "status": 1,
  "status_name": "active",
  "type": 5,
  "type_name": "WhatsApp",
  "channel": "whatsapp",
  "threads_count": 1,
  "delivery": { "channel": "whatsapp", "type": 5, "deliverable": true, "send_message": true }
}
```

#### Crear conversación de email (y enviarla)

```bash
curl -X POST https://tickets.qsoftware.biz/api/v1/conversations \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "mailbox_id": 3,
    "channel": "email",
    "subject": "Pedido #42",
    "to": ["cliente@example.com"],
    "cc": ["copia@example.com"],
    "body": "Adjuntamos el detalle del pedido.",
    "send_message": true
  }'
```

> Sin `send_message`, el email **solo se crea** (comportamiento histórico de la API).

#### Conversación de teléfono

```bash
curl -X POST https://tickets.qsoftware.biz/api/v1/conversations \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{ "mailbox_id": 3, "type": 2, "subject": "Llamada entrante",
        "phone": "+34 600 11 22 33", "name": "Jane Doe", "body": "Resumen de la llamada" }'
```

### 5.2 Hilos (mensajes/notas)

| Método | Ruta | Descripción |
| --- | --- | --- |
| `GET` | `/conversations/{id}/threads` | Lista hilos (`per_page` hasta 200). |
| `POST` | `/conversations/{id}/threads` | Añade un hilo. `type`: `2`=mensaje, `3`=nota, `8`=chat. |
| `GET` | `/threads/{id}` | Detalle del hilo. |
| `PUT` | `/threads/{id}` | Edita el cuerpo (solo el autor). |
| `DELETE` | `/threads/{id}` | Elimina el hilo. |

El medio lo define la conversación, no el hilo. Un **mensaje** (`type=2`) se puede entregar;
una **nota** (`type=3`) es interna y **nunca** se envía. En una conversación de WhatsApp, un
mensaje se entrega por defecto.

```bash
# Responder por WhatsApp
curl -X POST https://tickets.qsoftware.biz/api/v1/conversations/1234/threads \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{ "type": 2, "body": "Te confirmamos disponibilidad." }'
```

```json
{
  "id": 9876, "conversation_id": 1234, "type": 2, "type_name": "message",
  "body": "Te confirmamos disponibilidad.", "send_status": 0, "send_status_name": "pending",
  "delivery": { "channel": "whatsapp", "type": 5, "deliverable": true, "send_message": true }
}
```

Estado de entrega (`send_status_name`): `pending`, `accepted`, `send_error`, `intermediate_error`.

### 5.3 Clientes

| Método | Ruta | Descripción |
| --- | --- | --- |
| `GET` | `/customers` | Lista paginada. Filtro: `search` (nombre o email). |
| `POST` | `/customers` | Crea cliente (`first_name`, `last_name`, `emails[]`, `phone`). `409` si el email ya existe. |
| `GET` | `/customers/{id}` | Detalle + `recent_conversations`. |
| `PUT` | `/customers/{id}` | Actualiza `first_name`, `last_name`, `phone`, `emails[]`. |
| `DELETE` | `/customers/{id}` | Elimina cliente. `409 customer_has_conversations` si tiene conversaciones. |

### 5.4 Paginación

Respuesta de lista:

```json
{
  "data": [ /* ... */ ],
  "meta": { "total": 120, "page": 1, "per_page": 20, "last_page": 6 },
  "links": { "first": "…", "last": "…", "next": "…", "prev": null }
}
```

---

## 6. Errores

Todas las respuestas de error siguen el mismo formato:

```json
{
  "message": "WhatsApp is not configured for this mailbox",
  "status_code": 422,
  "error": "whatsapp_not_configured",
  "errors": { "mailbox_id": ["WhatsApp is not configured for this mailbox"] }
}
```

| HTTP | `error` | Cuándo |
| --- | --- | --- |
| `401` | — | Falta la cabecera `Authorization` / token inválido o caducado. |
| `403` | — | El token no tiene acceso a ese buzón. |
| `404` | — | Recurso no encontrado. |
| `422` | `validation` | Fallo de validación del payload (ver `errors`). |
| `422` | `whatsapp_not_configured` | Se pidió entregar por WhatsApp y el buzón no tiene Evolution API configurada. |
| `422` | `email_not_configured` | Se pidió `send_message=true` y el buzón no tiene salida de correo configurada. |
| `422` | `phone_required` | Conversación de teléfono/WhatsApp sin `phone` ni `customer_id`. |
| `422` | `unsupported_channel` | `channel`/`type` no soportado. |
| `422` | `customer_unresolved` | No se pudo resolver ni crear el cliente. |
| `409` | `customer_has_conversations` | Se intentó borrar un cliente con conversaciones. |
| `429` | — | Límite de tasa superado. |
| `500` | — | Error interno (queda en el log de FreeScout). |

---

## 7. Flujo de entrega

```mermaid
sequenceDiagram
    participant Q as QChat
    participant API as API /api/v1
    participant DB as FreeScout
    participant Q1 as Cola "emails"
    participant W as Evolution API
    participant SMTP as SMTP

    Q->>API: POST /conversations (channel=whatsapp)
    API->>API: Valida token + buzón + config WhatsApp
    alt Sin configurar
        API-->>Q: 422 whatsapp_not_configured
    else Configurado
        API->>DB: Crea cliente + conversación (type=5) + hilo
        API->>Q1: Encola SendWhatsappReply
        API-->>Q: 201 (delivery.send_message=true)
        Q1->>W: POST /message/sendText/{instance}
        W-->>Q1: 200 OK
        Q1->>DB: send_status=accepted
    end

    Q->>API: POST /conversations (channel=email, send_message=true)
    API->>API: Valida salida del buzón
    API->>Q1: Encola SendReplyToCustomer
    Q1->>SMTP: Envía correo
```

---

## 8. Notas y limitaciones

- **Mensajes entrantes (webhooks):** aún no hay endpoint público que reciba eventos de Evolution API
  (`messages.upsert`). QChat debe crear las conversaciones/hilos vía API.
- **Adjuntos:** `send_message` envía solo el texto. Los adjuntos de WhatsApp aún no se entregan.
- **Registros de envío:** los envíos de WhatsApp se registran con el tipo de correo (limitación de
  `send_logs`); en la API se distinguen por `channel`/`send_status`.
- **Rendimiento:** la entrega es asíncrona; consulta `send_status_name` del hilo para saber si se
  aceptó.
