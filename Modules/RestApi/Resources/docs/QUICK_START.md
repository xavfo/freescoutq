# FreeScout REST API - Quick Start Guide

## Authentication

All API requests require a Bearer token in the Authorization header:

```bash
curl -H "Authorization: Bearer fs_xxxxxxxxxxxx" \
  https://your-freescout.com/api/v1/conversations
```

### Getting an API Token

```bash
# Create a new API token for user 1
php artisan restapi:create-token 1 "My Integration" --mailbox_ids=1,2

# List all tokens
php artisan restapi:list-tokens

# Revoke a token
php artisan restapi:revoke-token 1
```

---

## Usage Examples

### 1. Create a Conversation

```bash
curl -X POST https://your-freescout.com/api/v1/conversations \
  -H "Authorization: Bearer fs_xxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "mailbox_id": 1,
    "subject": "Customer needs help",
    "to": ["customer@example.com"],
    "body": "Thank you for contacting us.",
    "status": 1
  }'
```

**Response (201 Created):**
```json
{
  "id": 123,
  "mailbox_id": 1,
  "subject": "Customer needs help",
  "customer": {
    "name": "John Doe",
    "email": "customer@example.com"
  },
  "status": 1,
  "status_name": "active",
  "threads_count": 1,
  "created_at": "2026-04-15T10:30:00Z"
}
```

### 2. List Conversations

```bash
curl https://your-freescout.com/api/v1/conversations?status=1&page=1&per_page=20 \
  -H "Authorization: Bearer fs_xxxxxxxxxxxx"
```

**Query Parameters:**
- `status`: Filter by status (1=active, 2=pending, 3=closed, 4=spam)
- `customer_id`: Filter by customer
- `mailbox_id`: Filter by mailbox
- `assigned_to`: Filter by agent
- `sort`: Sort field (prefix with `-` for descending)
- `page`: Page number
- `per_page`: Items per page (max 100)

### 3. Add Reply to Conversation

```bash
curl -X POST https://your-freescout.com/api/v1/conversations/123/threads \
  -H "Authorization: Bearer fs_xxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "type": 2,
    "body": "Thank you for your inquiry. We will help you shortly.",
    "cc": [],
    "bcc": []
  }'
```

### 4. Update Conversation Status

```bash
curl -X PUT https://your-freescout.com/api/v1/conversations/123 \
  -H "Authorization: Bearer fs_xxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "status": 3,
    "assigned_to": 2
  }'
```

### 5. Create Customer

```bash
curl -X POST https://your-freescout.com/api/v1/customers \
  -H "Authorization: Bearer fs_xxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "John",
    "last_name": "Doe",
    "emails": ["john@example.com"],
    "phone": "+1234567890"
  }'
```

### 6. List Customers

```bash
curl "https://your-freescout.com/api/v1/customers?search=john&page=1" \
  -H "Authorization: Bearer fs_xxxxxxxxxxxx"
```

---

## Status Codes

| Code | Meaning |
|------|---------|
| 200 | Success |
| 201 | Created |
| 204 | No Content (deleted) |
| 400 | Bad Request (validation error) |
| 401 | Unauthorized (invalid token) |
| 403 | Forbidden (no permission) |
| 404 | Not Found |
| 429 | Rate Limit Exceeded |
| 500 | Server Error |

---

## Rate Limiting

Each API token has a rate limit (default: 1000 requests/hour). 

Response headers include:
- `X-RateLimit-Limit`: Max requests in window
- `X-RateLimit-Remaining`: Remaining requests
- `X-RateLimit-Reset`: Unix timestamp when window resets

**When limit exceeded (429):**
```json
{
  "message": "Rate limit exceeded. Retry after 3600 seconds",
  "status_code": 429,
  "retry_after": 3600
}
```

---

## JavaScript Example

```javascript
const API_TOKEN = 'fs_xxxxxxxxxxxx';
const API_BASE = 'https://your-freescout.com/api/v1';

async function createConversation(mailboxId, subject, email, body) {
  const response = await fetch(`${API_BASE}/conversations`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${API_TOKEN}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      mailbox_id: mailboxId,
      subject: subject,
      to: [email],
      body: body,
    }),
  });

  if (!response.ok) {
    throw new Error(`API Error: ${response.status}`);
  }

  return response.json();
}

async function listConversations(filters = {}) {
  const params = new URLSearchParams(filters);
  const response = await fetch(`${API_BASE}/conversations?${params}`, {
    headers: {
      'Authorization': `Bearer ${API_TOKEN}`,
    },
  });

  return response.json();
}

// Usage
createConversation(1, 'Test', 'user@example.com', 'Hello!')
  .then(conv => console.log('Created:', conv))
  .catch(err => console.error(err));
```

---

## Python Example

```python
import requests

API_TOKEN = 'fs_xxxxxxxxxxxx'
API_BASE = 'https://your-freescout.com/api/v1'

headers = {
    'Authorization': f'Bearer {API_TOKEN}',
    'Content-Type': 'application/json',
}

# Create conversation
conversation = requests.post(
    f'{API_BASE}/conversations',
    headers=headers,
    json={
        'mailbox_id': 1,
        'subject': 'Test',
        'to': ['customer@example.com'],
        'body': 'Hello!',
    }
).json()

print('Created:', conversation)

# List conversations
conversations = requests.get(
    f'{API_BASE}/conversations?status=1',
    headers=headers
).json()

print('Conversations:', conversations['data'])
```

---

## Postman Collection

Import this collection into Postman to test all endpoints:

[postman-collection.json](postman-collection.json)

---

## Troubleshooting

### "Invalid API token"
- Check token is correct
- Token must be prefixed with `Bearer `
- Token must be in Authorization header (not query param)

### "Unauthorized to access this mailbox"
- Your API token is only authorized for certain mailboxes
- Contact admin to add mailbox access to your token

### "Rate limit exceeded"
- Wait for the `retry_after` seconds before making new requests
- Or upgrade your rate limit via `restapi:create-token --rate_limit=5000`

---

For API documentation, see: `/api/v1/docs`
