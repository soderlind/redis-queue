=== Redis Queue ===
Contributors: PerS
Donate link: https://github.com/soderlind/redis-queue
Tags: redis, queue, background, jobs, performance
Requires at least: 6.7
Tested up to: 6.8
Requires PHP: 8.3
Stable tag: 2.0.1
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Redis-backed background job processing for WordPress: priority, delay, retries, REST API, token auth (scopes + rate limiting), logging, extensibility.

== Description ==

Redis Queue is a comprehensive WordPress plugin that demonstrates how to implement enterprise-grade background job processing using Redis queues. This plugin showcases effective techniques for handling time-consuming, resource-intensive, or critical tasks asynchronously, improving user experience and site performance.

**Key Features (2.0.0):**

* **Background Job Processing**: Handle time-consuming tasks without blocking user interactions
* **REST API Integration**: Complete REST API for worker management, job creation, stats & health
* **Multiple Job Types**: Email processing, image optimization, and API synchronization examples
* **Admin Dashboard**: Comprehensive admin interface for monitoring, purge tools, debug test
* **Token Authentication**: Optional API token with scopes (worker/full)
* **Rate Limiting**: Per-token requests/minute enforcement
* **Request Logging**: JSON lines logging with rotation & retention settings
* **Real-time Monitoring**: Live job status tracking and performance metrics
* **WordPress Integration**: Follows WordPress coding standards and development guidelines

**Use Cases Demonstrated:**

* Email Operations (bulk sending, notifications)
* Image Processing (thumbnails, optimization, watermarks)
* Data Import/Export (CSV, XML processing)
* Content Processing (search indexing, cache warming)
* Third-party API Integration (social media, CRM sync)
* E-commerce Operations (order processing, inventory sync)

**Technical Highlights:**

* Redis PHP extension and Predis library support
* Custom MySQL tables for job metadata
* Comprehensive error handling, retry backoff, and extensible logging
* Token scope filtering & rate limiting hooks
* Security with WordPress nonces and capability checks
* Extensible architecture for custom job types

== Installation ==

**Prerequisites:**
* WordPress 6.7 or higher
* PHP 8.3 or higher
* Redis server (local or remote)
* Redis PHP extension or Predis library

**Installation Steps:**

1. Upload the plugin files to `/wp-content/plugins/redis-queue/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure Redis connection in the plugin settings
4. Visit the Redis Queue Dashboard to start using the plugin

**Redis Setup:**

For local development with Homebrew:
`
    brew install redis
    brew services start redis
`
For production environments, configure Redis connection settings in the plugin admin panel.

== Frequently Asked Questions ==

= What is Redis and why do I need it? =

Redis is an in-memory data structure store used as a database, cache, and message broker. This plugin uses Redis to queue background jobs, preventing time-consuming tasks from blocking your website's user interface.

= Do I need technical knowledge to use this plugin? =

This is a developer-focused demonstration plugin. Basic understanding of WordPress development and Redis concepts is recommended for implementation in production environments.

= Can I extend this plugin with custom job types? =

Yes! The plugin is designed with extensibility in mind. You can create custom job classes by extending the base job interface and registering them with the queue manager.

= Is this plugin production-ready? =

This plugin demonstrates proven techniques and includes comprehensive error handling. However, it's designed as a learning tool and reference implementation. Review and test thoroughly before production use.

= What happens if Redis is unavailable? =

The plugin includes fallback mechanisms and graceful error handling. Jobs will fail gracefully with appropriate logging when Redis is unavailable.

== Screenshots ==

1. **Admin Dashboard** - Overview of queue status, job statistics, and system health
2. **Job Browser** - Detailed view of all jobs with filtering and search capabilities
3. **Worker Management** - REST API endpoints for triggering and managing background workers
4. **Test Interface** - Tools for testing different job types and queue operations
5. **Settings Panel** - Redis connection configuration and plugin options


== Changelog ==

= 2.0.1 =
* Added: Filter `redis_queue_show_test_jobs_page` to optionally hide the Test Jobs admin page (useful on production).
* Added: New documentation file `docs/filters.md` enumerating all available filters.
* Changed: Defensive access guard when page is disabled.
* Note: Non-breaking refinement over 2.0.0.

= 2.0.0 =
* **BREAKING CHANGES - Major version update**
* Plugin renamed from "Redis Queue Demo" to "Redis Queue"
* Namespace changed from `Soderlind\RedisQueueDemo` to `Soderlind\RedisQueue`
* Function names changed from `redis_queue_demo_*()` to `redis_queue_*()`
* Filter/action hooks changed from `redis_queue_demo_*` to `redis_queue_*`
* Text domain changed from `redis-queue-demo` to `redis-queue`
* GitHub repository URLs updated to reflect new name
* Removed all backward compatibility layers and legacy aliases
* One-time automatic migration of options from old to new prefix
* Complete documentation update to reflect new naming
* See CHANGELOG.md for detailed migration guide

 * Post-release adjustments (still 2.0.0, non-breaking):
     * Improved test job submission feedback: enforced brief minimum "Processing..." state so users perceive action.
     * Reworked loading indicator: spinner removed; simple fade animation keeps original button colors.
     * Fixed admin JS enqueue path (relative `../../` replaced with `plugins_url()` to avoid edge cases in some setups).
     * Added a <noscript> warning on Test Jobs page for users with JavaScript disabled.
     * Minor internal JS housekeeping (debug preload marker) to help diagnose asset loading.

= 1.2.0 =
* Removed legacy global class aliases and all back-compat shims
* Deleted deprecated `includes/` directory (fully namespaced codebase)
* Dropped legacy job_type inference variants; only canonical types accepted
* Removed fallback to global `Sync_Worker`; namespaced worker required
* Documentation cleanup to reflect canonical usage & namespaced classes
* General refactor / modernization pass

= 1.0.2 =
* Added GitHub updater class and release automation workflows
* Added funding configuration
* Added README documentation hyperlinks section
* Updated composer.json metadata (description, keywords)
* Version bump to 1.0.2

= 1.0.1 =
* Added documentation index & extensibility guide (`docs/README.md`, `extending-jobs.md`, `usage.md`)
* Added token scopes (worker/full) with filters to customize allowed routes
* Implemented per-token rate limiting (configurable)
* Added structured request logging with rotation & retention
* Refactored primary `README.md` to concise overview with links
* Improved null-safe failure handling and retry decision logic
* Added filter examples in REST API docs
* Internal adjustments for future scaling docs

= 1.0.0 =
* Initial release
* Complete Redis queue implementation
* REST API for worker management
* Admin dashboard and monitoring tools
* Three demonstration job types (email, image, API sync)
* Comprehensive documentation and examples
* WordPress 6.7+ and PHP 8.3+ compatibility

== Upgrade Notice ==

= 2.0.1 =
Adds filter to disable Test Jobs page in production and introduces a filters reference doc (`docs/filters.md`).

= 2.0.0 =
MAJOR BREAKING CHANGES: Plugin renamed to "Redis Queue". Namespace, function names, and hooks all changed. Review migration guide in CHANGELOG.md before upgrading. No automatic backward compatibility.

= 1.2.0 =
Removes deprecated legacy compatibility layers. Only namespaced classes and canonical job types remain.

= 1.0.2 =
Adds GitHub-based updates, enriched docs & metadata. No database changes.

= 1.0.1 =
Documentation + security enhancements (token scopes, rate limiting, logging). No database changes.

= 1.0.0 =
Initial release of the Redis Queue plugin.

