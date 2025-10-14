# Filters Reference

This document lists all public WordPress filters exposed by the Redis Queue plugin (excluding third‑party library filters). Use these hooks to customize behaviour without modifying core plugin code.

> Prefix: All filters start with `redis_queue_`.

## Table of Contents
- [UI / Admin](#ui--admin)
- [Job Creation & Mapping](#job-creation--mapping)
- [Job Retry & Backoff](#job-retry--backoff)
- [Job Payload Validation](#job-payload-validation)
- [REST API Token Scopes](#rest-api-token-scopes)

---
## UI / Admin
### `redis_queue_show_test_jobs_page` ([source](../src/Admin/Admin_Interface.php#L43))
Controls whether the "Test Jobs" submenu appears in the admin and whether the page can be accessed directly.

| Type | Default | Since |
|------|---------|-------|
| bool  | `true` | 2.0.1 |

Return `false` to hide the menu and block direct access (useful for production hardening).

```php
// In mu-plugin or theme functions.php
add_filter( 'redis_queue_show_test_jobs_page', '__return_false' );
```

---
## Job Creation & Mapping
### `redis_queue_create_job` ([source](../src/Core/Redis_Queue.php#L315))
Allows creation of custom job instances for dynamic job types that are not part of the built‑in set (`email`, `image_processing`, `api_sync`).

Signature:
```php
apply_filters( 'redis_queue_create_job', $job_or_null, string $job_type, array $payload );
```
- `$job_or_null` (mixed): Always `null` on input; return a `Queue_Job` instance to handle the type.
- `$job_type` (string): Requested job type.
- `$payload` (array): Raw payload passed by caller.

Return: `\Soderlind\RedisQueue\Contracts\Queue_Job|null`.

Example:
```php
add_filter( 'redis_queue_create_job', function( $job, $type, $payload ) {
    if ( 'report_generation' !== $type ) {
        return $job; // leave untouched
    }
    return new \MyPlugin\Jobs\Report_Generation_Job( $payload );
}, 10, 3 );
```

### `redis_queue_job_classes` ([source](../src/Core/Job_Processor.php#L227))
Extends or overrides the canonical mapping from simple job type identifiers (lowercase) to fully qualified job class names used during deserialization / processing by the `Job_Processor`.

Signature:
```php
apply_filters( 'redis_queue_job_classes', array $map );
```
- `$map` (array): Base mapping like `['email' => Email_Job::class, ...]`.

Return: Modified associative array.

Example (add `report_generation`):
```php
add_filter( 'redis_queue_job_classes', function( $map ) {
    $map['report_generation'] = \MyPlugin\Jobs\Report_Generation_Job::class;
    return $map;
});
```

---
## Job Retry & Backoff
### `redis_queue_should_retry_job` ([source](../src/Jobs/Abstract_Base_Job.php#L136))
Determines if a failed job should be retried (only consulted if current attempts < max_attempts).

Signature:
```php
apply_filters( 'redis_queue_should_retry_job', bool $should_retry, Queue_Job $job, ?\Exception $exception, int $attempt );
```
- `$should_retry` (bool): Default `true` after basic guards.
- `$job` (Queue_Job): Job instance.
- `$exception` (?Exception): The exception that caused failure or `null` when failure was non-exception based.
- `$attempt` (int): Current attempt number (1-based) that just failed.

Return: `bool` (retry or not).

Example (disable retries on specific exception):
```php
add_filter( 'redis_queue_should_retry_job', function( $retry, $job, $exception, $attempt ) {
    if ( $exception instanceof \MyPlugin\FatalRemoteAPIException ) {
        return false; // don't bother retrying
    }
    return $retry;
}, 10, 4 );
```

### `redis_queue_job_retry_delay` ([source](../src/Jobs/Abstract_Base_Job.php#L160))
Adjusts delay (seconds) before a retry attempt is re-enqueued.

Signature:
```php
apply_filters( 'redis_queue_job_retry_delay', int $delay, Queue_Job $job, int $attempt );
```
- `$delay` (int): Computed delay (configured backoff item or exponential fallback capped at 3600s).
- `$job` (Queue_Job): Job instance.
- `$attempt` (int): Attempt number that just failed.

Return: New delay (int).

Example (progressive jitter):
```php
add_filter( 'redis_queue_job_retry_delay', function( $delay, $job, $attempt ) {
    return (int) ( $delay * ( 0.9 + mt_rand() / mt_getrandmax() * 0.2 ) );
}, 10, 3 );
```

---
## Job Payload Validation
### `redis_queue_validate_job_payload` ([source](../src/Jobs/Abstract_Base_Job.php#L199))
Override or augment validation of a job payload.

Signature:
```php
apply_filters( 'redis_queue_validate_job_payload', bool $is_valid, array $payload, Queue_Job $job );
```
- `$is_valid` (bool): Default `true`.
- `$payload` (array): Job payload.
- `$job` (Queue_Job): Job instance.

Return: `bool` (allow enqueue / continue processing).

Example (require key):
```php
add_filter( 'redis_queue_validate_job_payload', function( $valid, $payload, $job ) {
    if ( 'report_generation' === $job->get_job_type() && empty( $payload['report_id'] ) ) {
        return false;
    }
    return $valid;
}, 10, 3 );
```

---
## REST API Token Scopes
These filters control what endpoints a token with a restricted scope may access.

### `redis_queue_token_allowed_routes` ([source](../src/API/REST_Controller.php#L229))
Defines the list of allowable REST routes for a non-`full` scope token before per-request evaluation.

Signature:
```php
apply_filters( 'redis_queue_token_allowed_routes', array $routes, string $scope );
```
- `$routes` (array): Default `[ '/redis-queue/v1/workers/trigger' ]` when scope != `full`.
- `$scope` (string): Token scope, e.g. `worker` or `full`.

Return: Adjusted list of route paths.

Example (allow stats endpoint):
```php
add_filter( 'redis_queue_token_allowed_routes', function( $routes, $scope ) {
    if ( 'worker' === $scope ) {
        $routes[] = '/redis-queue/v1/stats';
    }
    return $routes;
}, 10, 2 );
```

### `redis_queue_token_scope_allow` ([source](../src/API/REST_Controller.php#L232))
Final gate to allow/deny a request for a token (after URL match). Use to implement dynamic rules (time windows, IP allowlists, etc.).

Signature:
```php
apply_filters( 'redis_queue_token_scope_allow', bool $allowed, string $scope, WP_REST_Request $request );
```
- `$allowed` (bool): Result after earlier checks.
- `$scope` (string): Token scope.
- `$request` (WP_REST_Request): Full request object.

Return: `bool` (permit request?).

Example (block trigger outside office hours):
```php
add_filter( 'redis_queue_token_scope_allow', function( $allowed, $scope, $request ) {
    if ( 'worker' === $scope ) {
        $hour = (int) gmdate('G');
        if ( $hour < 6 || $hour > 22 ) {
            return false; // Outside allowed window
        }
    }
    return $allowed;
}, 10, 3 );
```

---
## Notes
- All filters follow standard WordPress priority & argument count conventions.
- Always return the original value when not modifying behaviour to maintain chain integrity.
- Avoid expensive operations inside hot-path filters (`job_retry_delay`, `should_retry_job`).

Happy extending! 🚀
