=== Redis Queue Demo ===
Contributors: PerS
Donate link: https://github.com/soderlind/redis-queue-demo
Tags: redis, queue, background, jobs, performance
Requires at least: 6.7
Tested up to: 6.7
Requires PHP: 8.3
Stable tag: 1.0.0
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Demonstrates Redis queue implementation for WordPress background job processing with REST API worker management.

== Description ==

Redis Queue Demo is a comprehensive WordPress plugin that demonstrates how to implement enterprise-grade background job processing using Redis queues. This plugin showcases effective techniques for handling time-consuming, resource-intensive, or critical tasks asynchronously, improving user experience and site performance.

**Key Features:**

* **Background Job Processing**: Handle time-consuming tasks without blocking user interactions
* **REST API Integration**: Complete REST API for worker management and job creation
* **Multiple Job Types**: Email processing, image optimization, and API synchronization examples
* **Admin Dashboard**: Comprehensive admin interface for monitoring and management
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
* Comprehensive error handling and logging
* Security with WordPress nonces and capability checks
* Extensible architecture for custom job types

== Installation ==

**Prerequisites:**
* WordPress 6.7 or higher
* PHP 8.3 or higher
* Redis server (local or remote)
* Redis PHP extension or Predis library

**Installation Steps:**

1. Upload the plugin files to `/wp-content/plugins/redis-queue-demo/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure Redis connection in the plugin settings
4. Visit the Redis Queue Dashboard to start using the plugin

**Redis Setup:**

For local development with Homebrew:
```
brew install redis
brew services start redis
```

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

= 1.0.0 =
* Initial release
* Complete Redis queue implementation
* REST API for worker management
* Admin dashboard and monitoring tools
* Three demonstration job types (email, image, API sync)
* Comprehensive documentation and examples
* WordPress 6.7+ and PHP 8.3+ compatibility

== Upgrade Notice ==

= 1.0.0 =
Initial release of the Redis Queue Demo plugin. No upgrades available yet.

== Developer Information ==

**Author:** Per Soderlind
**Plugin URI:** https://soderlind.no
**GitHub:** https://github.com/soderlind/redis-queue-demo

This plugin serves as a comprehensive reference for implementing Redis-based background job processing in WordPress. It demonstrates enterprise-grade patterns while maintaining WordPress coding standards.

**Architecture Overview:**

* **Queue Manager**: Core Redis queue operations
* **Job Processor**: Handles job execution and lifecycle
* **REST API**: Complete API for external worker management
* **Admin Interface**: WordPress admin integration
* **Job Types**: Extensible job type system

**Contributing:**

This is a demonstration plugin designed for educational purposes. Feel free to use the code as a reference for your own implementations.

== Support ==

This plugin is provided as-is for educational and demonstration purposes. For questions about implementation or extending the functionality, please refer to the comprehensive documentation included with the plugin.

**Documentation Files:**
* README.md - Complete setup and usage guide
* IMPLEMENTATION_SUMMARY.md - Technical implementation details
* design.md - Original design document and architecture

For Redis-specific questions, consult the official Redis documentation at https://redis.io/documentation