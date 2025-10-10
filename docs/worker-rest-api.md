# Redis Queue Demo – REST API Documentation

Base Namespace: `redis-queue/v1`
Base URL Example: `https://example.com/wp-json/redis-queue/v1/`

All endpoints require ONE of the following:

1. An authenticated WP admin user (`manage_options`) with a valid REST nonce (`X-WP-Nonce: <nonce>`), OR
2. A valid API token generated on the plugin Settings page.

The API token grants the same access level as an admin for these endpoints; store it securely. You may send the token using either:

```
Authorization: Bearer <token>
```
or
```
X-Redis-Queue-Token: <token>
```

If both a WP auth context and a token are supplied, WP auth wins (token is ignored). If neither valid auth nor token is provided, the API returns HTTP 403.

---
## Authentication & Headers
Choose one auth method:

WP Auth (nonce + cookies):
```
X-WP-Nonce: <nonce value>
Content-Type: application/json (for JSON bodies)
```

Token Auth:
```
Authorization: Bearer <token>
Content-Type: application/json
```
or
```
X-Redis-Queue-Token: <token>
Content-Type: application/json
```

Notes:
- Nonce header is only required for cookie-based WP auth, not for token auth.
- Treat the token like a password. Regenerating on the settings page invalidates the old token.
- To revoke token access, clear the token on the settings page (save) so only WP auth works.
- Token Scope: By default the token scope is "worker" which only permits calling `/workers/trigger`. Setting scope to "full" (in Settings) allows all endpoints documented here. Developers can filter `redis_queue_demo_token_allowed_routes` or `redis_queue_demo_token_scope_allow` to fine-tune access.
- Rate Limiting: Token calls are limited per token per minute (default 60). Exceeding returns HTTP 429 `rate_limited` error.
- Request Logging: If enabled in settings, each call in this namespace is logged (JSON lines) containing timestamp, route, status, auth method, scope result, rate-limit flag, WP user ID (if any), and IP.

---
## Endpoints

### 1. List Jobs
GET `/jobs`

Query Parameters:
- `page` (int, default 1)
- `per_page` (int, default 10, max 100)
- `status` (string; one of queued, processing, completed, failed, cancelled)
- `queue` (string; queue name)

Response Headers:
- `X-WP-Total`: total matched jobs
- `X-WP-TotalPages`: total pages

Response Body (200): Array of Job Objects
```json
[
  {
    "id": "job_650fa...",
    "type": "email",
    "queue": "default",
    "status": "queued",
    "priority": 10,
    "payload": {"subject":"..."},
    "result": null,
    "attempts": 0,
    "max_attempts": 3,
    "timeout": 300,
    "error_message": null,
    "created_at": "2025-10-10 12:34:56",
    "updated_at": "2025-10-10 12:34:56",
    "processed_at": null,
    "failed_at": null
  }
]
```

### 2. Get Single Job
GET `/jobs/{id}`

Response (200): Job Object (same shape as above)
Errors:
- 404 `job_not_found`

### 3. Create Job
POST `/jobs`

Body (form-encoded or JSON):
```json
{
  "type": "email",            // required: email | image_processing | api_sync
  "payload": { ... },          // optional object (job specific)
  "priority": 50,              // optional (0 = highest priority)
  "queue": "default"          // optional queue name
}
```

Response (201/200):
```json
{
  "success": true,
  "job_id": "job_6510...",
  "message": "Job created and enqueued successfully."
}
```
Errors:
- 400 `invalid_job_type`
- 500 `enqueue_failed` or `job_creation_failed`

### 4. Delete (Cancel) Job
DELETE `/jobs/{id}`

Allowed only for jobs in `queued` or `failed` states. Marks status as `cancelled`.

Response (200):
```json
{
  "success": true,
  "job_id": "job_6510...",
  "message": "Job cancelled successfully."
}
```
Errors:
- 404 `job_not_found_or_not_cancellable`

### 5. Trigger Worker (Synchronous Batch)
POST `/workers/trigger`

Body:
```json
{
  "queues": ["default", "email"],  // optional array; default ["default"]
  "max_jobs": 10                    // optional int (1–100)
}
```

Response:
```json
{
  "success": true,
  "data": {
    "processed": 4,
    "successful": 3,
    "failed": 1,
    "errors": [],
    "success": true
  },
  "message": "Worker processed 4 jobs."
}
```
Errors:
- 500 `worker_execution_failed`

### 6. Worker Status
GET `/workers/status`

Response (example):
```json
{
  "success": true,
  "data": {
    "last_run": "2025-10-10 12:35:20",
    "processing": false,
    "recent_jobs": 10
  }
}
```
(Actual keys depend on `Sync_Worker::get_status()` implementation.)

### 7. Queue Stats
GET `/stats`

Returns raw stats structure containing per-queue counts, delayed queue, plus database summary.

Example:
```json
{
  "success": true,
  "data": {
    "default": {"pending": 2, "size": 2},
    "email": {"pending": 0, "size": 0},
    "delayed": {"pending": 1, "size": 1},
    "database": {
      "total": 5,
      "queued": 2,
      "processing": 0,
      "completed": 2,
      "failed": 1
    }
  }
}
```

(For the admin dashboard counts, the plugin internally flattens the `database` block.)

### 8. Health
GET `/health`

Response:
```json
{
  "success": true,
  "status": "healthy",
  "data": {
    "redis_connected": true,
    "redis_info": {
      "redis_version": "7.2.4",
      "used_memory": "812K",
      "connected_clients": 3
    },
    "database_status": true,
    "memory_usage": {"current": 1234567, "peak": 2233445, "limit": "256M"},
    "php_version": "8.3.1",
    "wordpress_version": "6.8",
    "plugin_version": "1.0.0"
  }
}
```

### 9. Clear Specific Queue
POST `/queues/{name}/clear`

Clears (removes) all **pending** jobs from a Redis queue key (does not purge historical DB rows).

Response:
```json
{
  "success": true,
  "message": "Queue \"email\" cleared successfully."
}
```
Errors:
- 400 `missing_queue_name`
- 500 `queue_clear_failed`

---
## Data Model (Job Object)
Field | Description
----- | -----------
`id` | Job identifier (string)
`type` | Job type (`email`, `image_processing`, `api_sync`, custom via filter)
`queue` | Queue name
`status` | queued, processing, completed, failed, cancelled
`priority` | Numeric priority (lower = higher priority)
`payload` | Original payload (decoded JSON)
`result` | Result payload (JSON) or null
`attempts` | Attempts already made
`max_attempts` | Maximum allowed attempts
`timeout` | Timeout (seconds)
`error_message` | Final error message on failure (if any)
`created_at` | Creation timestamp
`updated_at` | Last update timestamp
`processed_at` | First processing timestamp (or null)
`failed_at` | Failure timestamp (or null)

---
## Error Format
Errors follow WordPress REST WP_Error serialization:
```json
{
  "code": "job_not_found",
  "message": "Job not found.",
  "data": {"status": 404}
}
```

---
## Examples (curl)
Assuming you have a valid cookie / nonce context. Replace `SITE` with your domain.

List jobs (token auth):
```
curl -H "Authorization: Bearer $TOKEN" https://SITE/wp-json/redis-queue/v1/jobs
```

List jobs (WP nonce + cookies):
```
curl -H "X-WP-Nonce: <nonce>" https://SITE/wp-json/redis-queue/v1/jobs
```

Create job (token auth, JSON):
```
curl -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"type":"email","payload":{"to":"admin@example.com","subject":"Hi","message":"Body"},"priority":10}' \
  https://SITE/wp-json/redis-queue/v1/jobs
```

Create job (nonce + form):
```
curl -X POST -H "X-WP-Nonce: <nonce>" -d 'type=email&priority=10&queue=default' \
  --data-urlencode 'payload[{"to":"admin@example.com","subject":"Hi","message":"Body"}]' \
  https://SITE/wp-json/redis-queue/v1/jobs
```

Trigger worker (token auth):
```
curl -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"queues":["default","email"],"max_jobs":5}' \
  https://SITE/wp-json/redis-queue/v1/workers/trigger
```

Trigger worker (nonce):
### 10. Rate Limit Example Response
If exceeding the configured per-minute threshold:
```json
{
  "code": "rate_limited",
  "message": "Rate limit exceeded. Try again later.",
  "data": {"status": 429}
}
```

### 11. Token Scope Error Example
If token scope is worker-only and you call a disallowed endpoint:
```json
{
  "code": "rest_forbidden_scope",
  "message": "Token scope does not permit this endpoint.",
  "data": {"status": 403}
}
```

---
## Logging & Rotation
When enabled, logs are written to: `wp-content/uploads/redis-queue-demo-logs/requests.log`
Rotation occurs when the file exceeds the configured size (KB). Old logs are named `requests-YYYYmmdd-HHMMSS.log`. The oldest rotated files are pruned beyond the configured maximum count.

Log line sample (JSON):
```json
{"ts":"2025-10-10T12:45:03Z","method":"POST","route":"/redis-queue/v1/workers/trigger","status":200,"auth":"token","scope_ok":true,"rate_limited":false,"user_id":0,"ip":"203.0.113.10"}
```

Fields:
- ts: Timestamp (UTC ISO8601)
- method: HTTP method
- route: REST route
- status: HTTP status code
- auth: auth method used (cap or token or none)
- scope_ok: whether token scope allowed request
- rate_limited: true if the request was blocked by limiter (logged before response)
- user_id: WP user ID if authenticated via capability
- ip: Remote address (sanitized)

---
## Filters for Developers
- `redis_queue_demo_token_allowed_routes( array $routes, string $scope )` to override which routes a non-full scope token may call.
- `redis_queue_demo_token_scope_allow( bool $allowed, string $scope, WP_REST_Request $request )` for per-request dynamic decisions.

### Filter Examples

Allow a worker-scope token to call the stats & health endpoints in addition to the default trigger route:

```php
add_filter( 'redis_queue_demo_token_allowed_routes', function( $routes, $scope ) {
  if ( 'worker' === $scope ) {
    $routes[] = '/redis-queue/v1/stats';
    $routes[] = '/redis-queue/v1/health';
  }
  return array_unique( $routes );
}, 10, 2 );
```

Block a specific sensitive route for all tokens unless scope is full and request comes from an internal IP:

```php
add_filter( 'redis_queue_demo_token_scope_allow', function( $allowed, $scope, $request ) {
  $internal_ip = '10.0.0.5';
  $route       = $request->get_route();

  if ( '/redis-queue/v1/jobs' === $route && 'POST' === $request->get_method() ) {
    // Only permit creating jobs if scope is full and IP matches.
    $remote_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
    if ( 'full' !== $scope || $remote_ip !== $internal_ip ) {
      return false;
    }
  }
  return $allowed; // defer to previous logic otherwise
}, 10, 3 );
```

Completely disable rate-limited trigger bursts except for a whitelisted queue set (example: only allow worker token to trigger specific queues):

```php
add_filter( 'redis_queue_demo_token_scope_allow', function( $allowed, $scope, $request ) {
  if ( ! $allowed ) { return false; }
  if ( 'worker' !== $scope ) { return $allowed; }
  if ( '/redis-queue/v1/workers/trigger' !== $request->get_route() ) { return $allowed; }

  $body = json_decode( $request->get_body(), true );
  $queues = isset( $body['queues'] ) && is_array( $body['queues'] ) ? $body['queues'] : array( 'default' );
  $permitted = array( 'default', 'email' );
  foreach ( $queues as $q ) {
    if ( ! in_array( $q, $permitted, true ) ) {
      return false; // reject if any queue is outside the allowed set
    }
  }
  return true;
}, 10, 3 );
```

Fine-tune rate limits dynamically (example: lower limits for worker scope vs full scope). Core rate limiting currently uses a single per-minute value; this pattern shows how you might intercept before heavy routes and short-circuit access using the scope filter as a pseudo dynamic limiter:

```php
add_filter( 'redis_queue_demo_token_scope_allow', function( $allowed, $scope, $request ) {
  if ( ! $allowed ) { return false; }

  // Simple in-memory (transient) adaptive limiter per scope.
  $route = $request->get_route();
  $minute = gmdate('YmdHi');
  $key = 'rqdemo_dyn_' . $scope . '_' . $minute;
  $count = (int) get_transient( $key );
  $limit = ( 'full' === $scope ) ? 120 : 30; // full gets higher budget

  // Only apply to job creation or trigger endpoints.
  if ( in_array( $route, array( '/redis-queue/v1/jobs', '/redis-queue/v1/workers/trigger' ), true ) ) {
    $count++;
    if ( 1 === $count ) {
      set_transient( $key, 1, 60 - (int) gmdate('s') );
    } elseif ( $count > $limit ) {
      return false; // deny by exhausting custom dynamic budget
    } else {
      set_transient( $key, $count, 60 - (int) gmdate('s') );
    }
  }
  return $allowed;
}, 10, 3 );
```

Per-token differentiated limits (example: store a map of token hashes to custom budgets). Since the raw token is available only during permission check, you could extend core to capture it; here we assume you stored custom limits in an option keyed by sha256 hash:

```php
add_filter( 'redis_queue_demo_token_scope_allow', function( $allowed, $scope, $request ) {
  if ( ! $allowed ) { return false; }

  // Suppose you saved custom limits: option name 'redis_queue_demo_custom_token_limits'
  // Format: [ sha256(token) => [ 'limit' => 45 ] ]
  $settings = get_option( 'redis_queue_settings', array() );
  if ( empty( $settings['api_token'] ) ) { return $allowed; }
  $token = $settings['api_token'];
  $hash = hash('sha256', $token );
  $custom_limits = get_option( 'redis_queue_demo_custom_token_limits', array() );
  $limit = isset( $custom_limits[ $hash ]['limit'] ) ? (int) $custom_limits[ $hash ]['limit'] : 60;

  $minute = gmdate('YmdHi');
  $key = 'rqdemo_tok_' . substr( $hash, 0, 16 ) . '_' . $minute;
  $count = (int) get_transient( $key );
  $count++;
  if ( 1 === $count ) {
    set_transient( $key, 1, 60 - (int) gmdate('s') );
    return $allowed;
  }
  if ( $count > $limit ) {
    return false; // budget exceeded
  }
  set_transient( $key, $count, 60 - (int) gmdate('s') );
  return $allowed;
}, 10, 3 );
```

```
curl -X POST -H "X-WP-Nonce: <nonce>" -d 'queues[]==default&max_jobs=5' \
  https://SITE/wp-json/redis-queue/v1/workers/trigger
```

---
## Notes & Roadmap
- A flattened stats endpoint (`/stats?flat=1`) could be added for direct dashboard consumption (currently done via AJAX + internal flatten).
- Purge and maintenance actions are presently admin‑AJAX only; consider exposing controlled REST endpoints with stricter capability / nonce scopes.
- Add filtering by `job_type` in list endpoint if needed for large datasets.

---
Generated on: 2025-10-10
