# Maintenance & Operations

Best practices for keeping the queue system healthy over time.

## Routine Tasks
| Task | Frequency | Purpose |
|------|-----------|---------|
| Review failed jobs | Daily | Identify systemic errors |
| Purge old completed jobs | Weekly | Control DB growth |
| Rotate request logs | Automatic (size) | Ensure disk space hygiene |
| Verify Redis memory usage | Weekly | Prevent eviction / performance issues |
| Test worker trigger | Weekly | Early detection of environment regressions |

## Purging Strategies
Built-in purge tools exist via admin UI. Recommended policies:
- Completed jobs older than 7 days
- Failed jobs older than 30 days (after triage)
- Cancelled jobs older than 7 days

Consider a WP-CLI command (future enhancement) for scripted purges.

## Handling Stuck Jobs
Symptoms: Jobs remain `processing` beyond expected timeout.
1. Run the “Reset Stuck Jobs” admin action (resets to `queued`).
2. Investigate root cause (PHP fatal, external timeout).
3. Add defensive timeouts inside job execution.

## Log Management
Request logging (if enabled) rotates by size:
- Tune `log_rotate_size_kb` for expected traffic
- Adjust `log_max_files` based on retention needs
- Centralize logs (e.g., ship to ELK) for correlation across hosts

## Database Hygiene
Potential future cron to purge directly:
```php
// Sketch example
add_action( 'redis_queue_demo_maintenance', function() {
  global $wpdb; $t = $wpdb->prefix . 'redis_queue_jobs';
  $wpdb->query( $wpdb->prepare("DELETE FROM $t WHERE status='completed' AND created_at < %s", gmdate('Y-m-d H:i:s', time()-7*DAY_IN_SECONDS) ) );
});
```
Schedule with:
```php
if ( ! wp_next_scheduled( 'redis_queue_demo_maintenance' ) ) {
  wp_schedule_event( time()+300, 'hourly', 'redis_queue_demo_maintenance' );
}
```

## Capacity Planning
Indicators to add more workers or increase batch size:
- Sustained backlog growth (queued count rising hour-over-hour)
- High average wait time (difference between `created_at` and `processed_at`)
- Repeated rate limiting or API throttling requiring slower pacing

## Disaster Recovery
If Redis data is lost:
- Database table still has historical job metadata but jobs in-flight or queued are gone
- Reconstruct critical jobs by scanning for statuses not `completed` and re-enqueue with a script

## Security Upkeep
- Rotate API token periodically (document internal runbook)
- Audit allowed routes for scoped tokens via new filter examples
- Monitor logs for anomalous route access patterns

## Metric Ideas (Not yet implemented)
- Queue length over time per queue
- Job execution latency distribution
- Failure rate by job type
- Retry attempt histogram

## Future Enhancements
- CLI commands for purge & requeue
- Metrics endpoint / integration export
- Automatic backoff tuning based on failure types

See `scaling.md` for throughput strategies.
