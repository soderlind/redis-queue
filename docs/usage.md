# Usage & Operations Guide

This document centralizes day-to-day usage, programmatic patterns, advanced features, troubleshooting, and performance practices for the Redis Queue plugin.

## Table of Contents
1. Admin Interface
2. REST API Basics
3. Programmatic Usage (PHP)
4. Advanced Features
5. Troubleshooting
6. Performance Optimization
7. Security Notes

---
## 1. Admin Interface

### Dashboard
- Real-time queue statistics
- Manual worker trigger
- Health & processing summary

### Job Management
- Browse, filter, and inspect jobs
- Cancel queued / failed jobs
- View payload, result, and error metadata

### Test Interface
Quickly create test jobs:

Email Job Example
```
Type: Single Email
To: admin@example.com
Subject: Test Email
Message: Testing Redis queue system
```

Image Processing Example
```
Operation: Generate Thumbnails
Attachment ID: 123
Sizes: thumbnail, medium, large
```

API Sync Example
```
Operation: Webhook
URL: https://httpbin.org/post
Data: {"test": "message"}
```

---
## 2. REST API Basics

All endpoints live under: `/wp-json/redis-queue/v1/`

See `docs/worker-rest-api.md` for full, authoritative reference including token authentication, scopes, rate limiting, and logging format.

Common endpoints:
- `POST /jobs` create a job
- `GET /jobs/{id}` fetch job details
- `POST /workers/trigger` process jobs synchronously
- `GET /stats` queue statistics
- `GET /health` health summary

Example: Create a Job
```bash
curl -X POST "https://yoursite.com/wp-json/redis-queue/v1/jobs" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -d '{
    "type": "email",
    "payload": {
      "to": "user@example.com",
      "subject": "Hello World",
      "message": "This is a test email"
    },
    "priority": 10,
    "queue": "default"
  }'
```

---
## 3. Programmatic Usage (PHP)

### Creating & Enqueuing Jobs
```php
use Soderlind\RedisQueue\Jobs\Email_Job;

$email_job = new Email_Job([
  'email_type' => 'single',
  'to'         => 'user@example.com',
  'subject'    => 'Welcome!',
  'message'    => 'Welcome to our site!'
]);

$email_job->set_priority(10);
$email_job->set_queue_name('emails');

$job_id = redis_queue()->get_queue_manager()->enqueue( $email_job );
```

### Processing Jobs Manually
```php
use Soderlind\RedisQueue\Workers\Sync_Worker;

$worker = new Sync_Worker(
  redis_queue()->get_queue_manager(),
  redis_queue()->get_job_processor()
);
$results = $worker->process_jobs( [ 'default', 'emails' ], 5 );
```

### Custom Job Skeleton
See [`docs/extending-jobs.md`](extending-jobs.md) for full guidance.
```php
class Custom_Job extends Abstract_Base_Job {
    public function get_job_type() { return 'custom_job'; }
    public function execute() {
        $data = $this->get_payload();
        // Do work
        return $this->success(['processed' => true]);
    }
}
```

---
## 4. Advanced Features

### Priorities
`0` (highest) → `100` (lowest)
```php
$urgent->set_priority(0);
$low->set_priority(90);
```

### Delayed Execution
```php
$job->set_delay_until(time() + 3600); // run in 1 hour
```

### Multiple Queues
```php
$email_job->set_queue_name('emails');
$image_job->set_queue_name('images');
```

### Error Handling & Retries
- Automatic retries w/ exponential backoff
- Override `should_retry()` for granular logic
- Failures preserved for inspection

### Monitoring Metrics
Surfaces counts, success/failure, durations, backlog depths via admin + `/stats` endpoint.

---
## 5. Troubleshooting

### Redis Connection Failed
```
Error: Redis connection failed
```
Fix Checklist:
1. `redis-cli ping` returns PONG
2. Confirm host/port in settings
3. Validate firewall / container network
4. Check password auth

### Jobs Stuck / Not Processing
- Trigger worker manually
- Inspect PHP error logs
- Validate payload JSON structure
- Reduce batch size / memory usage

### Memory Exhaustion
```
Fatal error: Allowed memory size exhausted
```
Mitigations:
1. Lower batch size
2. Raise PHP memory limit
3. Audit custom job memory usage
4. Process fewer queues per invocation

### Debug Mode
```php
define( 'REDIS_QUEUE_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

### Health Endpoint
```bash
curl -s https://yoursite.com/wp-json/redis-queue/v1/health
```

---
## 6. Performance Optimization

### Redis Tuning (indicative)
```
maxmemory-policy allkeys-lru
save 900 1
tcp-keepalive 60
timeout 300
```

### WordPress / PHP
```php
define( 'WP_MEMORY_LIMIT', '512M' );
ini_set( 'max_execution_time', 300 );
```

### Worker Scheduling
Cron / supervisor strategies:
```
* * * * * wp eval "redis_queue()->process_jobs();"
```
Consider external runners for higher throughput.

---
## 7. Security Notes

- REST auth via capability or API token
- Token scopes (`worker` vs `full`) restrict endpoint access
- Per-token rate limiting (default 60/min configurable)
- Optional structured request logging with rotation (JSON lines)
- Sanitize + validate payloads; avoid storing secrets raw
- Use encryption or references for sensitive data

For full details see [`docs/worker-rest-api.md`](worker-rest-api.md).

---
Made with ❤️  See [README](README.md) for overview & links.
