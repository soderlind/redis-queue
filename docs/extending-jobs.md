# Extending Jobs

This guide explains how to create custom background jobs by extending the base abstractions provided by Redis Queue.

## Core Concepts

Important: The plugin now exclusively uses namespaced classes and only canonical job type identifiers you define (e.g. `email`, `image_processing`, `api_sync`, or your custom strings). Legacy/global class name variants (like `Email_Job`, `email_job`) are not auto-mapped.

A job represents a unit of work executed asynchronously by a worker. Each job class encapsulates:

- A unique job type identifier (string)
- A payload (arbitrary associative array stored as JSON)
- Optional execution constraints (timeout, queue name, priority, delay)
- Logic to produce a success or failure `Job_Result`
- Retry decision logic (`should_retry()`)
- Optional additional failure handling (`handle_failure()`)

## Base Class: `Abstract_Base_Job`

`Abstract_Base_Job` (located in `jobs/abstract-base-job.php`) implements common functionality:

- Payload storage & retrieval helpers (`get_payload()`, `get_payload_value()`)
- Setters for priority, queue, timeout, delay
- Standard serialization for Redis + DB metadata
- Convenience `success()` / `failure()` result constructors
- Default retry strategy (delegates to job processor limits)
- Hook points for failure handling

### Minimal Custom Job Example

```php
class Report_Generation_Job extends Abstract_Base_Job {
    public function __construct( array $payload = [] ) {
        parent::__construct( $payload );
        $this->queue_name = 'reports';   // logical queue name (optional)
        $this->priority   = 40;          // lower = higher priority
        $this->timeout    = 300;         // seconds
    }

    public function get_job_type() {
        return 'report_generation';
    }

    public function execute() {
        try {
            $report_id = $this->get_payload_value( 'report_id' );
            if ( ! $report_id ) {
                return $this->failure( 'Missing report_id' );
            }

            // Perform work (pseudo logic)
            $data   = $this->generate_report( $report_id );
            $stored = $this->store_report( $report_id, $data );

            if ( ! $stored ) {
                return $this->failure( 'Failed to persist report' );
            }

            return $this->success(
                [ 'report_id' => $report_id, 'size' => strlen( json_encode( $data ) ) ],
                [ 'operation' => 'generate' ]
            );
        } catch ( Throwable $e ) {
            return $this->failure( $e->getMessage(), $e->getCode() );
        }
    }

    private function generate_report( $report_id ) { /* ... */ return [ 'rows' => [] ]; }
    private function store_report( $report_id, $data ) { /* ... */ return true; }
}
```

### Enqueuing Your Custom Job

```php
$job = new Report_Generation_Job( [ 'report_id' => 42 ] );
$job->set_priority( 10 );            // optional
$job->set_queue_name( 'reports' );   // optional
$job->set_delay_until( time() + 600 ); // run in 10 minutes

$job_id = redis_queue()->get_queue_manager()->enqueue( $job );
```

### Processing

Jobs are automatically picked up when the worker processes the queue that contains them:

```php
$results = redis_queue_process_jobs( [ 'default', 'reports' ], 20 );
```

### Success & Failure Results

Use helper methods inside `execute()`:

```php
return $this->success( [ 'key' => 'value' ], [ 'meta_key' => 'meta_value' ] );
return $this->failure( 'Problem occurred', $error_code, [ 'context' => 'extra' ] );
```

- Success data is stored in the `result` column.
- Metadata is supplemental and can aid debugging.
- Failure stores `error_message` + metadata.

### Retry Logic

Override `should_retry( $exception, $attempt )` to customize behavior. Examples:

```php
public function should_retry( $exception, $attempt ) {
    // No retries after 3 attempts regardless.
    if ( $attempt >= 3 ) {
        return false;
    }

    // Do not retry on validation errors.
    if ( $exception instanceof Exception && str_contains( $exception->getMessage(), 'validation' ) ) {
        return false;
    }

    return parent::should_retry( $exception, $attempt );
}
```

### Failure Handling Hook

```php
public function handle_failure( $exception, $attempt ) {
    parent::handle_failure( $exception, $attempt );
    // Custom logging / metrics.
    do_action( 'my_plugin_report_job_failed', $this, $exception, $attempt );
}
```

### Creating Helper Factory Methods

Pattern (see `Email_Job`):

```php
public static function create_report( $report_id ) {
    return new self( [ 'report_id' => (int) $report_id ] );
}
```

### Registering / Filtering Custom Types

If you want dynamic creation based on a string type (e.g., via REST), add a filter where the plugin instantiates jobs. For this plugin, extend `redis_queue_create_job` filter (see `redis-queue-demo.php`):

```php
add_filter( 'redis_queue_create_job', function( $job, $job_type, $payload ) {
    if ( $job ) { return $job; }
    if ( 'report_generation' === $job_type ) {
        return new Report_Generation_Job( $payload );
    }
    return $job;
}, 10, 3 );
```

### Choosing Queues

Use different queues to isolate workloads (e.g., `email`, `media`, `api`, `reports`). Call worker with all relevant queues.

### Delayed Jobs

```php
$job->set_delay_until( strtotime( '+15 minutes' ) );
```

Internally the job is placed in a delayed sorted set until due.

### Priority Guidelines

- 0–10: Critical / time sensitive
- 11–40: High (e.g., user-facing operations)
- 41–70: Normal
- 71–100: Low / maintenance

### Debugging Tips

- Use the admin “Full Debug Test” for baseline health.
- Inspect the job record in DB table `wp_redis_queue_jobs`.
- Check Redis keys with the configured prefix (`redis-cli KEYS "*redis_queue*"`).
- Enable request logging & look at log lines for REST interactions.

### Best Practices

1. Keep `execute()` idempotent where possible (safe to retry).
2. Validate payload early; return failure for logical issues (avoid retry noise).
3. Set sensible timeouts — long-running tasks should chunk work.
4. Avoid large payload blobs; store large data elsewhere and reference IDs.
5. Include contextual metadata in `success()` or `failure()` for observability.
6. Use dedicated queues for heavy or low-priority tasks.
7. Consider adding circuit breakers for upstream API failures.

### Example: API Wrapper Job Skeleton

```php
class External_Api_Job extends Abstract_Base_Job {
    public function get_job_type() { return 'external_api'; }
    public function execute() {
        $endpoint = $this->get_payload_value( 'endpoint' );
        if ( ! $endpoint ) { return $this->failure( 'Missing endpoint' ); }
        try {
            $resp = wp_remote_get( $endpoint );
            if ( is_wp_error( $resp ) ) {
                return $this->failure( $resp->get_error_message() );
            }
            $code = wp_remote_retrieve_response_code( $resp );
            if ( $code >= 400 ) {
                return $this->failure( 'HTTP ' . $code );
            }
            $body = wp_remote_retrieve_body( $resp );
            return $this->success( [ 'code' => $code, 'length' => strlen( $body ) ] );
        } catch ( Throwable $e ) {
            return $this->failure( $e->getMessage(), $e->getCode() );
        }
    }
}
```

---
**Next:** See `docs/usage.md` (to be created) for general usage, and the REST API doc for remote job creation.
