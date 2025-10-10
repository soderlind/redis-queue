# Scaling Workers & Throughput

Guidance for scaling Redis Queue Demo beyond a single synchronous worker.

## Objectives
- Increase parallel job throughput
- Isolate workload classes (emails vs image processing)
- Prevent contention and long tail latency
- Maintain observability & fairness

## Horizontal Scaling Pattern
Run multiple PHP worker processes (CLI or cron-triggered) each targeting specific queue sets:

```
* * * * * wp eval "redis_queue_process_jobs( ['email'], 25 );" >/dev/null 2>&1
* * * * * wp eval "redis_queue_process_jobs( ['image','api'], 15 );" >/dev/null 2>&1
```

Consider process supervisors (systemd, Supervisor, PM2 for long-running `wp eval-file` loops) if you want always-on workers rather than cron bursts.

## Queue Segmentation
Use dedicated queues per workload:
- `email`: Latency-sensitive notifications
- `media`: CPU-intensive image tasks
- `api`: External API sync / webhooks
- `reports`: Batch or heavy analytics

Benefits: targeted concurrency, easier rate limiting, independent failure domains.

## Priority Strategy
Reserve lowest numeric priorities for interactive or SLA-bound tasks (0–20). Assign background enrichment mid-range (40–60) and maintenance jobs higher numbers (80+).

## Avoiding Stampedes
When scaling horizontally:
1. Limit batch sizes (e.g. 10–50) per invocation.
2. Stagger cron offsets (*/1 but with sleep or offset per host) to reduce thundering herd.
3. Optionally implement a distributed lock (SETNX) around expensive global jobs.

## Delayed Jobs Processing Cadence
Delayed jobs are promoted when a worker runs. To reduce promotion jitter:
- Ensure at least one worker loop executes every minute.
- For tighter SLAs, run a lightweight promotion worker every 15s (loop with sleep(15)).

## Observability Enhancements (Future Ideas)
- Add Prometheus-friendly metrics endpoint (queue length, processed counts).
- Track per-job-type mean/95p execution time.
- Emit structured events (action hooks exist) to a logging system.

## Backpressure & Rate Control
If upstream APIs throttle:
- Use job-specific retry backoff customization.
- Enforce token scope + filter-controlled queue triggers.
- Introduce a queue-level semaphore key (e.g. only N `api` jobs per minute) by wrapping dequeue logic.

## Safety Checklist
- Monitor Redis memory (avoid eviction of delayed ZSET)
- Keep PHP memory limit high enough for concurrent image jobs
- Rotate logs to prevent disk growth
- Validate custom job classes for idempotency

## Next Steps
See `maintenance.md` for retention, purging, and stuck job recovery strategies.
