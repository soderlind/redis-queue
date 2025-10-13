# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog and adheres to Semantic Versioning.


## [2.0.0] - 2025-10-13
### Breaking Changes
- **Plugin renamed**: "Redis Queue Demo" → "Redis Queue"
- **Namespace changed**: `Soderlind\RedisQueueDemo` → `Soderlind\RedisQueue` across all classes
- **Function names changed**: `redis_queue_demo_*()` → `redis_queue_*()`
- **Filter/action names changed**: `redis_queue_demo_*` → `redis_queue_*`
- **Text domain changed**: `redis-queue-demo` → `redis-queue`
- **GitHub repository**: URLs updated from `redis-queue-demo` to `redis-queue`
- **Composer package**: Will be `soderlind/redis-queue` (currently `soderlind/redis-queue-demo`)
- **Download file name**: Changed from `redis-queue-demo.zip` to `redis-queue.zip`
- **No backward compatibility**: Legacy class aliases and function names have been completely removed

### Migration Guide
To migrate from 1.x to 2.0.0:

1. **Update namespace imports**:
   - Old: `use Soderlind\RedisQueueDemo\Core\Redis_Queue_Manager;`
   - New: `use Soderlind\RedisQueue\Core\Redis_Queue_Manager;`

2. **Update function calls**:
   - Old: `redis_queue_demo()`
   - New: `redis_queue()`
   - Old: `redis_queue_demo_create_job` filter
   - New: `redis_queue_create_job` filter

3. **Update action/filter hooks**:
   - Old: `add_filter('redis_queue_demo_token_allowed_routes', ...)`
   - New: `add_filter('redis_queue_token_allowed_routes', ...)`

4. **Update options migration**: A one-time migration automatically renames options from `redis_queue_demo_*` to `redis_queue_*` on first activation of 2.0.0.

### Changed
- Complete documentation overhaul to reflect new plugin name and namespace
- All code examples updated to use new function and filter names
- REST API namespace remains `redis-queue/v1` for API stability

## [1.2.0] - 2025-10-10
### Removed
- Dropped all legacy global class aliases (previous `class_alias` guards) now that backward compatibility is not required.
- Removed deprecated `includes/` directory and emptied legacy interface stubs.
- Removed legacy job_type inference variants (e.g. email_job, Email_Job, image_processing_job, api_sync_job); only canonical keys `email`, `image_processing`, `api_sync` are accepted now.
- Removed bootstrap fallback to legacy global `Sync_Worker` (namespaced worker is now required).
- Deleted development-only `debug.php` script (replaced by internal diagnostics via `Redis_Queue_Manager::diagnostic()`).
### Changed
- Codebase now exclusively uses namespaced classes; no global fallbacks remain.
- Documentation updated to remove legacy references (includes/ directory, global class name variants) and reflect canonical job type usage only.

## [1.0.2] - 2025-10-10
### Added
- GitHub updater integration and release workflows (`.github/workflows/*`, `class-github-plugin-updater.php`).
- Funding configuration (`.github/FUNDING.yml`).
- Expanded root README with direct links to documentation set.
- Composer metadata: enriched description & keywords.

### Changed
- Bumped plugin version to 1.0.2.

### Fixed
- Minor documentation cross-link clarity improvements.

## [1.0.1] - 2025-10-10
### Added
- Documentation restructuring: Added `docs/README.md` index, `docs/extending-jobs.md`, and `docs/usage.md`.
- New filter examples for token scope & route customization in `worker-rest-api.md`.
- Advanced guidance: created scaling & maintenance docs placeholders (to be added) and extensibility guide.
- Rate limiting, token scopes, and request logging documented.

### Changed
- Refactored `README.md` to concise overview with links.
- Improved failure handling semantics (null-safe exception handling in jobs).

### Fixed
- Minor doc inconsistencies and clarified token scope behavior.

## [1.0.0] - 2025-10-01
### Added
- Initial release: core Redis-backed queue system (priority, delay, retries).
- Built-in jobs: Email, Image Processing, API Sync.
- Admin dashboard, job browser, test tools, purge utilities.
- REST API with job management, stats, health, worker trigger.
- Basic logging and retry backoff strategies.
