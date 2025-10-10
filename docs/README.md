# Documentation Index

Central hub for Redis Queue Demo plugin documentation.

## Guides

| Topic | Description | Path |
|-------|-------------|------|
| Overview & Quick Start | Feature overview, installation basics, architecture snapshot | `../README.md` |
| Usage & Operations | Day-to-day usage, admin UI, REST basics, troubleshooting, performance | `usage.md` |
| Extending Jobs | How to build custom job classes & best practices | `extending-jobs.md` |
| REST API Reference | Endpoints, auth (token scopes, rate limit), logging, errors | `worker-rest-api.md` |
| Scaling Strategies | Horizontal scaling, queue segmentation, backpressure | `scaling.md` |
| Maintenance & Operations | Purging, stuck jobs, log rotation, DR, future improvements | `maintenance.md` |

## Navigation

You can open these docs directly inside your editor or view them on GitHub. Links from the root `README.md` also point here.

## Adding New Docs

When adding new documentation:

1. Create a Markdown file under `docs/` with a succinct name.
2. Add a concise heading and a short introductory sentence.
3. Update this index table with Topic, Description, and relative Path.
4. Cross-link from other docs where relevant.

Recommended future additions:
- `security-hardening.md` (advanced filtering, custom token rotation strategies)

## Key Concepts Recap

- **Jobs**: Serializable units of background work (email sending, image processing, API calls, custom tasks).
- **Queues**: Named priority lists stored in Redis, can be segmented by workload.
- **Delayed Set**: Sorted set holding jobs scheduled for future execution.
- **Worker**: Synchronous processor (invoked via admin, REST, or cron) that dequeues, executes, retries with backoff.
- **Token Scopes**: Restrict API token to `worker` (trigger-only) or `full` (all endpoints) with filter overrides.
- **Rate Limiting**: Per-token per-minute request control to mitigate abuse.
- **Request Logging**: Optional JSON lines logging with rotation for auditing & debugging.

## Quick Links

- Project Root README: `../README.md`
- License: `../LICENSE`

---
Happy queuing!
