# Redis Queue Documentation Hub

This directory is the structured knowledge base for the Redis Queue plugin. It gives you: quick orientation, deep dives, extension guidance, and operational playbooks.

---
## 1. At a Glance
Redis Queue provides a Redis‑backed, retryable, priority + delayed job system integrated with WordPress (admin UI + REST API).

| Capability | Highlights |
|------------|-----------|
| Job Types | Email, Image Processing, API Sync (reference implementations) |
| Features | Priority, delay, retries with backoff, cancellation, stuck reset, purge |
| Interfaces | WP Admin dashboard + REST API (`/wp-json/redis-queue/v1/`) |
| Security | Nonces, capability checks, token auth w/ scopes + rate limiting |
| Observability | Health endpoint, stats, optional structured request logging |
| Extensibility | Custom jobs, filters for token scopes & routes, pluggable retries |

---
## 2. Conceptual Architecture (Text Diagram)
```
 ┌────────────┐   enqueue()   ┌──────────────┐
 │ WP / REST  │──────────────▶│ Queue Manager│────────┐
 └────┬───────┘                └────┬─────────┘        │
	  │  Admin UI (AJAX/REST)       │                  │
	  │                             ▼                  │
	  │                      Redis (lists + zset)      │
	  │                             │                  │
	  │   trigger worker            ▼                  │
	  └────────────────────────▶ Job Processor ◀───────┘
								   │
								   ▼
							 Job Classes (Email / Image / API / Custom)
```

---
## 3. Quick Start Workflow
1. Install & activate plugin (ensure Redis extension or Predis available).
2. Configure Redis connection under: Admin → Redis Queue → Settings.
3. Use Test Jobs page to enqueue sample tasks.
4. Run a synchronous worker:
   * Click “Trigger Worker” in Dashboard, or
   * Call REST: `POST /wp-json/redis-queue/v1/workers/trigger` (authorized), or
   * Use helper: `redis_queue_process_jobs()` in custom code/cron.
5. Inspect Jobs page for status, payload, attempts, result, error messages.

---
## 4. Creating a Custom Job (Micro Example)
```php
namespace MyPlugin; 

use Soderlind\RedisQueue\Jobs\Abstract_Base_Job; 

class Report_Generation_Job extends Abstract_Base_Job {
	protected string $type = 'report_generation';

	public function handle() : array {  // Return structured result
		// Do work (generate file, etc.)
		$path = '/tmp/report-' . uniqid() . '.csv';
		file_put_contents($path, "id,value\n1,42");
		return [ 'file' => $path ];
	}
}

// Enqueue somewhere (after plugin init):
redis_queue_enqueue_job( 'report_generation', [ 'user' => get_current_user_id() ], [ 'priority' => 40 ] );

// Then hook into creation if using dynamic types:
add_filter('redis_queue_create_job', function($job, $type, $payload){
	if ($job || $type !== 'report_generation') return $job;
	return new Report_Generation_Job($payload);
}, 10, 3);
```
Full guide: see [Extending Jobs](extending-jobs.md).

---
## 5. REST API Highlights
| Purpose | Method & Endpoint | Notes |
|---------|------------------|-------|
| Create job | `POST /jobs` | `type`, `payload`, optional `priority`, `queue` |
| List jobs | `GET /jobs` | Filter by `status` or `queue` |
| Single job | `GET /jobs/{id}` | Returns payload + result + status meta |
| Cancel job | `DELETE /jobs/{id}` | Only for `queued` / `failed` |
| Trigger worker | `POST /workers/trigger` | Accepts `queues[]`, `max_jobs` |
| Stats | `GET /stats` | Aggregated queue metrics |
| Health | `GET /health` | Redis + DB + environment snapshot |

Auth paths & token scope logic: see [REST API Reference](worker-rest-api.md).

---
## 6. Operational Playbook (Cheat Sheet)
| Task | How |
|------|-----|
| Reset stuck jobs | Admin → Dashboard (button) or custom CLI calling manager reset |
| Purge old/completed | Admin → Purge buttons or custom SQL / manager call |
| Adjust retries/backoff | Filter / override in job class or queue config |
| Enable request logging | Settings → Enable logging (rotates automatically) |
| Scale horizontally | Multiple workers hitting same Redis + DB (see [Scaling](scaling.md)) |

---
## 7. Performance & Scaling Pointers
High-latency tasks: prefer batching in a single job payload vs enqueuing thousands of micro-jobs when practical. Use priority to separate latency-sensitive tasks. For heavy media or API bursts see [Scaling](scaling.md) for segmentation & backpressure patterns.

---
## 8. Maintenance Essentials
| Concern | Recommendation |
|---------|---------------|
| Table size growth | Periodic purge of completed / failed beyond retention window |
| Log rotation | Tune size & file count in Settings; ship logs externally if needed |
| Redis memory | Use dedicated DB index; monitor key counts & memory fragmentation |
| Stuck workers | Schedule health checks; use stuck reset tool proactively |

More in [Maintenance](maintenance.md).

---
## 9. Security & Governance (Summary)
* Capabilities: Admin UI guarded by `manage_options`.
* Token auth: Optional bearer token with scope filtering (`worker` vs `full`).
* Rate limiting: Per-token transient-based window.
* Nonces: REST (X-WP-Nonce) & admin AJAX (custom nonce) used for CSRF mitigation.
* Extensible: Filters allow customizing allowed routes & scope checks.

Potential future doc: `security-hardening.md` (advanced token rotation, audit aggregation).

---
## 10. Index of Detailed Guides
| Topic | Description | Path |
|-------|-------------|------|
| Overview & Root README | Feature overview, installation basics | [Root README](../README.md) |
| Usage & Operations | Admin UI, job lifecycle, troubleshooting | [Usage](usage.md) |
| Extending Jobs | Build custom job classes & best practices | [Extending Jobs](extending-jobs.md) |
| REST API Reference | Endpoints, auth (scopes, rate limit), logging | [REST API](worker-rest-api.md) |
| Scaling Strategies | Horizontal + segmentation + backpressure | [Scaling](scaling.md) |
| Maintenance & Operations | Purging, stuck jobs, logs, DR | [Maintenance](maintenance.md) |

---
## 11. Contributing / Adding Docs
1. Create a new `docs/<topic>.md` with a clear H1.
2. State purpose in the first paragraph (why, not just what).
3. Link related docs (bidirectional where useful).
4. Update the index table above.
5. Keep prose concise; move edge-case depth to sub‑sections.

---
## 12. Key Concepts (Recap)
Jobs • Queues • Delayed Set • Worker • Token Scopes • Rate Limiting • Request Logging.

---
## 13. Quick Links
* Root README: [../README.md](../README.md)
* License: [../LICENSE](../LICENSE)

---
Happy queuing! 🚀
