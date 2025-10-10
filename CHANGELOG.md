# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog and adheres to Semantic Versioning.

## [Unreleased]
### Removed
- Deleted development-only `debug.php` script (replaced by internal diagnostics via `Redis_Queue_Manager::diagnostic()`).

## [1.2.0] - 2025-10-10
### Removed
- Dropped all legacy global class aliases (previous `class_alias` guards) now that backward compatibility is not required.
- Removed deprecated `includes/` directory and emptied legacy interface stubs.
- Removed legacy job_type inference variants (e.g. email_job, Email_Job, image_processing_job, api_sync_job); only canonical keys `email`, `image_processing`, `api_sync` are accepted now.
- Removed bootstrap fallback to legacy global `Sync_Worker` (namespaced worker is now required).
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
