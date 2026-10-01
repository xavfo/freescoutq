# FreeScout REST API Module

A comprehensive REST API module for FreeScout that enables programmatic communication, conversation management, and customer handling through secure API endpoints.

## Features

✅ **Authentication**: Bearer token-based API authentication  
✅ **Authorization**: Per-user mailbox restrictions  
✅ **Rate Limiting**: Configurable rate limits per API token  
✅ **Conversations**: Create, read, update, delete conversations  
✅ **Threads**: Add replies, notes, and manage message history  
✅ **Customers**: Create and manage customer profiles  
✅ **Webhooks**: Real-time event notifications  
✅ **Audit Logging**: Track all API calls  
✅ **OpenAPI/Swagger**: Full API documentation  
✅ **Tests**: Comprehensive test suite included  

## Installation

### 1. Activate the Module

```bash
# List modules
php artisan module:list

# Activate (if it appears as inactive)
php artisan module:enable rest-api

# Or from Admin panel: Modules → REST API → Activate
```

### 2. Run Migrations

```bash
# Install module and run migrations
php artisan freescout:module-install rest-api
```

### 3. Create Your First API Token

```bash
# Create token for user 1, allow mailboxes 1 and 2
php artisan restapi:create-token 1 "Integration Name" --mailbox_ids=1,2

# Or without mailbox restriction (all mailboxes)
php artisan restapi:create-token 1 "Full Access"
```

The command will output your API token. Store it securely!

## Quick Start

### Authentication

All requests require a Bearer token:

```bash
curl -H "Authorization: Bearer fs_xxxxxxxxxxxx" \
  https://your-domain.com/api/v1/conversations
```

### Create a Conversation

```bash
curl -X POST https://your-domain.com/api/v1/conversations \
  -H "Authorization: Bearer fs_xxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "mailbox_id": 1,
    "subject": "Customer Inquiry",
    "to": ["customer@example.com"],
    "body": "Thank you for contacting us"
  }'
```

### Add a Reply

```bash
curl -X POST https://your-domain.com/api/v1/conversations/123/threads \
  -H "Authorization: Bearer fs_xxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "type": 2,
    "body": "This is my response to the inquiry"
  }'
```

### List Conversations

```bash
curl "https://your-domain.com/api/v1/conversations?status=1&page=1" \
  -H "Authorization: Bearer fs_xxxxxxxxxxxx"
```

See [QUICK_START.md](./Resources/docs/QUICK_START.md) for more examples.

## API Documentation

### Available Endpoints

#### Conversations
- `GET /api/v1/conversations` - List conversations
- `POST /api/v1/conversations` - Create conversation
- `GET /api/v1/conversations/{id}` - Get conversation
- `PUT /api/v1/conversations/{id}` - Update conversation
- `DELETE /api/v1/conversations/{id}` - Delete conversation

#### Threads
- `GET /api/v1/conversations/{id}/threads` - List threads
- `POST /api/v1/conversations/{id}/threads` - Add thread/reply
- `GET /api/v1/threads/{id}` - Get thread
- `PUT /api/v1/threads/{id}` - Update thread
- `DELETE /api/v1/threads/{id}` - Delete thread

#### Customers
- `GET /api/v1/customers` - List customers
- `POST /api/v1/customers` - Create customer
- `GET /api/v1/customers/{id}` - Get customer

### Response Format

All responses are JSON with HTTP status codes:

```json
{
  "id": 123,
  "subject": "Support Request",
  "status": 1,
  "status_name": "active",
  "created_at": "2026-04-15T10:30:00Z"
}
```

Error responses include details:

```json
{
  "message": "Validation error",
  "status_code": 422,
  "errors": {
    "email": ["Invalid email format"]
  }
}
```

### Authentication & Authorization

- **Tokens**: Each integration gets a unique Bearer token
- **Mailbox Scoping**: Tokens can be restricted to specific mailboxes
- **Expiration**: Optional token expiration dates
- **Rate Limiting**: 1000 requests/hour (configurable per token)

### Full OpenAPI Specification

See `/api/v1/docs` (when Swagger UI is enabled) or [openapi.yaml](./Resources/docs/openapi.yaml)

## Token Management

### Create Token

```bash
php artisan restapi:create-token {user_id} {name}
  [--mailbox_ids=1,2,3]
  [--rate_limit=1000]
  [--expires=2026-12-31]
```

### List Tokens

```bash
php artisan restapi:list-tokens

# Filter by user
php artisan restapi:list-tokens --user_id=1
```

### Revoke Token

```bash
php artisan restapi:revoke-token {token_id}
```

## Webhooks

Automatic webhooks notify your application when events occur:

### Supported Events

- `conversation.created` - New conversation created
- `conversation.status_changed` - Conversation status changed
- `thread.created` - Message/reply added to conversation

### Example Webhook Payload

```json
{
  "event": "conversation.created",
  "timestamp": "2026-04-15T10:30:00Z",
  "data": {
    "conversation_id": 123,
    "subject": "Customer Inquiry",
    "customer": "John Doe",
    "mailbox_id": 1,
    "created_by": "Support Agent"
  }
}
```

### Signature Verification

Each webhook includes an `X-Webhook-Signature` header with HMAC-SHA256 signature:

```php
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'];
$secret = 'your_webhook_secret';

$computed = hash_hmac('sha256', $payload, $secret);
if (hash_equals($computed, $signature)) {
    // Valid webhook
}
```

## Configuration

Edit `config/rest-api.php`:

```php
'api' => [
    'version' => 'v1',
    'prefix' => 'api',
    'rate_limit_default' => 1000,
    'rate_limit_window' => 3600,
],

'webhooks' => [
    'enabled' => true,
    'timeout' => 10,
    'retries' => 3,
    'backoff_factor' => 2,
],

'logging' => [
    'enabled' => true,
    'log_failed_requests' => true,
],

'cors' => [
    'enabled' => false,
    'allowed_origins' => ['*'],
],
```

## Rate Limiting

Each API token has a rate limit (default: 1000 requests/hour).

Response headers:
- `X-RateLimit-Limit`: Total requests allowed
- `X-RateLimit-Remaining`: Requests left
- `X-RateLimit-Reset`: Unix timestamp when limit resets

When exceeded: `429 Too Many Requests`

```json
{
  "message": "Rate limit exceeded. Retry after 3600 seconds",
  "status_code": 429,
  "retry_after": 3600
}
```

## Audit Logging

All API calls are logged in `api_audit_logs` table with:
- User ID
- API Token ID
- HTTP Method & endpoint
- Response code & time
- IP address

Query logs:
```bash
php artisan tinker
>>> DB::table('api_audit_logs')->latest()->limit(10)->get();
```

## Error Handling

| Status | Meaning |
|--------|---------|
| 200 | Success |
| 201 | Created |
| 204 | No Content (deleted) |
| 400 | Bad Request |
| 401 | Unauthorized (invalid token) |
| 403 | Forbidden (no permission) |
| 404 | Not Found |
| 422 | Validation Error |
| 429 | Rate Limit Exceeded |
| 500 | Server Error |

## Testing

Run the test suite:

```bash
# All tests
php artisan test --filter=RestApi

# Specific test class
php artisan test Tests/Feature/Api/ConversationsApiTest

# With coverage
php artisan test --filter=RestApi --coverage
```

## Security

⚠️ **Important Security Notes:**

1. **Token Storage**: Keep tokens secure, treat as passwords
2. **HTTPS Only**: Always use HTTPS in production
3. **Token Rotation**: Rotate tokens periodically
4. **Scope Limitation**: Restrict tokens to specific mailboxes
5. **Expiration**: Set expiration dates when possible
6. **Webhook Verification**: Always verify webhook signatures

## Troubleshooting

### "Invalid API token"
- Verify token is correct
- Check Authorization header format: `Bearer {token}`
- Ensure token hasn't expired
- Verify token is still active (not revoked)

### "Unauthorized to access this mailbox"
- Token is scoped to different mailboxes
- Request admin to add mailbox to your token

### "Rate limit exceeded"
- Wait for window to reset (see `X-RateLimit-Reset`)
- Or request higher rate limit via `restapi:create-token --rate_limit=5000`

### Webhooks not firing
- Verify webhook URL is accessible
- Check webhook `active` status in `api_webhooks` table
- Review logs in `last_error_message`
- Verify event type matches subscription

## Support

For issues or feature requests:
- GitHub: [freescoutapp/freescout](https://github.com/freescoutapp/freescout)
- Docs: [api-docs.freescout.net](https://api-docs.freescout.net)

## License

AGPL-3.0 - See LICENSE file
