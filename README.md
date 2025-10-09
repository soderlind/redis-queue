# Redis Queue Demo for WordPress

A comprehensive WordPress plugin demonstrating Redis queues for background job processing. This plugin showcases how to implement a robust queue system for WordPress, handling email operations, image processing, API integrations, and more.

## Features

### 🚀 **Core Queue System**
- **Redis Integration**: Supports both Redis PHP extension and Predis library
- **Priority Queues**: Jobs can be prioritized for processing order
- **Delayed Jobs**: Schedule jobs to run at specific times
- **Retry Logic**: Automatic retry with exponential backoff for failed jobs
- **Job Metadata**: Comprehensive tracking and logging
- **Memory Management**: Built-in memory limits and timeout handling

### 📧 **Email Processing Jobs**
- **Single Emails**: Individual email sending
- **Bulk Emails**: Mass email campaigns
- **Newsletter Distribution**: Large-scale newsletter sending
- **Template Support**: HTML and plain text emails
- **Attachment Handling**: File attachments support

### 🖼️ **Image Processing Jobs**
- **Thumbnail Generation**: Multiple size thumbnails
- **Image Optimization**: Compression and resizing
- **Watermark Application**: Brand protection
- **Format Conversion**: Convert between image formats
- **Batch Processing**: Handle multiple images efficiently

### 🔗 **API Integration Jobs**
- **Social Media Posting**: Automated social media updates
- **CRM Synchronization**: Customer data sync
- **Webhook Processing**: Handle incoming webhook data
- **Third-party APIs**: Generic API integration patterns
- **Rate Limiting**: Respect API rate limits

### 🛠️ **REST API Interface**
- **Job Management**: Create, read, update, delete jobs
- **Worker Control**: Trigger workers via API
- **Queue Statistics**: Monitor queue performance
- **Health Checks**: System status monitoring
- **Authentication**: WordPress user capabilities

### 📊 **Admin Interface**
- **Dashboard**: Real-time queue statistics
- **Job Browser**: View and manage all jobs
- **Test Interface**: Create test jobs easily
- **Settings Page**: Configure Redis and queue settings
- **Health Monitor**: System status overview

## Installation

### Prerequisites

1. **WordPress**: Version 6.7 or higher
2. **PHP**: Version 8.3 or higher
3. **Redis Server**: Running Redis instance
4. **Redis PHP Extension** OR **Predis Library**: One of these for Redis connectivity

### Redis Setup

#### Option 1: Install Redis PHP Extension
```bash
# Ubuntu/Debian
sudo apt-get install php-redis

# macOS with Homebrew
brew install php-redis

# CentOS/RHEL
sudo yum install php-redis
```

#### Option 2: Install Predis via Composer
```bash
# In your WordPress root or plugin directory
composer require predis/predis
```

### Plugin Installation

1. **Download/Clone** this plugin to your WordPress plugins directory:
```bash
cd wp-content/plugins/
git clone https://github.com/persoderlind/redis-queue-demo.git
```

2. **Activate** the plugin through the WordPress admin interface

3. **Configure Redis** settings in the plugin settings page

## Configuration

### Redis Settings

Navigate to **Redis Queue > Settings** in your WordPress admin to configure:

- **Redis Host**: Your Redis server hostname (default: 127.0.0.1)
- **Redis Port**: Redis server port (default: 6379)
- **Redis Database**: Database number 0-15 (default: 0)
- **Redis Password**: Authentication password (if required)
- **Worker Timeout**: Maximum job execution time (default: 30 seconds)
- **Max Retries**: Failed job retry attempts (default: 3)
- **Retry Delay**: Base delay between retries (default: 60 seconds)
- **Batch Size**: Jobs per worker execution (default: 10)

### Environment Variables

You can also configure via environment variables or wp-config.php:

```php
// wp-config.php
define( 'REDIS_QUEUE_HOST', '127.0.0.1' );
define( 'REDIS_QUEUE_PORT', 6379 );
define( 'REDIS_QUEUE_PASSWORD', 'your-password' );
define( 'REDIS_QUEUE_DATABASE', 0 );
```

## Usage

### 1. Admin Interface

#### Dashboard
- View real-time queue statistics
- Monitor system health
- Trigger workers manually
- View job processing results

#### Job Management
- Browse all jobs with filtering
- View detailed job information
- Cancel queued or failed jobs
- Monitor job status changes

#### Test Interface
Create test jobs to verify functionality:

**Email Job Example:**
```
Type: Single Email
To: admin@example.com
Subject: Test Email
Message: Testing Redis queue system
```

**Image Processing Example:**
```
Operation: Generate Thumbnails
Attachment ID: 123
Sizes: thumbnail, medium, large
```

**API Sync Example:**
```
Operation: Webhook
URL: https://httpbin.org/post
Data: {"test": "message"}
```

### 2. REST API Usage

#### Authentication
All API endpoints require WordPress authentication with `manage_options` capability.

#### Create a Job
```bash
curl -X POST "https://yoursite.com/wp-json/redis-queue/v1/jobs" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: your-nonce" \
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

#### Trigger Worker
```bash
curl -X POST "https://yoursite.com/wp-json/redis-queue/v1/workers/trigger" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: your-nonce" \
  -d '{
    "queues": ["default"],
    "max_jobs": 10
  }'
```

#### Get Queue Statistics
```bash
curl -X GET "https://yoursite.com/wp-json/redis-queue/v1/stats" \
  -H "X-WP-Nonce: your-nonce"
```

#### System Health Check
```bash
curl -X GET "https://yoursite.com/wp-json/redis-queue/v1/health" \
  -H "X-WP-Nonce: your-nonce"
```

### 3. Programmatic Usage

#### Creating Jobs in Code

```php
// Get the plugin instance
$redis_queue = redis_queue_demo();

// Create an email job
$email_job = new Email_Job([
    'email_type' => 'single',
    'to' => 'user@example.com',
    'subject' => 'Welcome!',
    'message' => 'Welcome to our site!'
]);

// Set job properties
$email_job->set_priority(10);
$email_job->set_queue_name('emails');

// Enqueue the job
$job_id = $redis_queue->queue_manager->enqueue($email_job);
```

#### Processing Jobs

```php
// Get worker instance
$worker = new Sync_Worker(
    $redis_queue->queue_manager,
    $redis_queue->job_processor
);

// Process jobs from specific queues
$results = $worker->process_jobs(['default', 'emails'], 5);

echo "Processed: " . $results['processed'] . " jobs\n";
echo "Successful: " . $results['successful'] . "\n";
echo "Failed: " . $results['failed'] . "\n";
```

#### Custom Job Types

Create your own job types by extending the base job class:

```php
class Custom_Job extends Abstract_Base_Job {
    
    public function execute() {
        $data = $this->get_payload();
        
        // Your custom job logic here
        $result = $this->process_custom_data($data);
        
        return new Basic_Job_Result(
            true,
            'Custom job completed successfully',
            $result
        );
    }
    
    public function get_job_type() {
        return 'custom_job';
    }
    
    private function process_custom_data($data) {
        // Implement your custom processing logic
        return ['processed' => true, 'timestamp' => time()];
    }
}
```

## WordPress Integration Benefits

### Why Use Redis Queues in WordPress?

1. **Performance**: Offload heavy tasks from HTTP requests
2. **Reliability**: Jobs are persisted and can survive server restarts
3. **Scalability**: Distribute work across multiple workers
4. **User Experience**: Non-blocking operations keep sites responsive
5. **Error Handling**: Automatic retries and failure management

### Ideal Use Cases

#### Email Operations
- **Newsletter Campaigns**: Send thousands of emails without timeout
- **Transactional Emails**: Reliable delivery with retry logic
- **Email Templates**: Process complex HTML emails efficiently

#### Image Processing
- **Media Uploads**: Generate thumbnails in background
- **Bulk Operations**: Process multiple images efficiently
- **CDN Uploads**: Async upload to external storage

#### API Integrations
- **Social Media**: Schedule posts across platforms
- **CRM Systems**: Sync customer data reliably
- **Analytics**: Send tracking data without blocking users

#### Content Operations
- **Search Indexing**: Update search indices asynchronously
- **Cache Warming**: Pre-generate cache for better performance
- **Data Imports**: Process large datasets without timeouts

## Advanced Features

### Job Priorities

Jobs support priority levels (0-100, lower = higher priority):

```php
$urgent_job->set_priority(0);    // Highest priority
$normal_job->set_priority(50);   // Normal priority
$low_job->set_priority(100);     // Lowest priority
```

### Delayed Execution

Schedule jobs to run at specific times:

```php
$job->set_delay_until(time() + 3600); // Run in 1 hour
```

### Queue Management

Create separate queues for different job types:

```php
$email_job->set_queue_name('emails');
$image_job->set_queue_name('images');
$api_job->set_queue_name('api_calls');
```

### Error Handling

Jobs include comprehensive error handling:

- **Automatic Retries**: Failed jobs are automatically retried
- **Exponential Backoff**: Increasing delays between retries
- **Error Logging**: Detailed error messages and stack traces
- **Dead Letter Queue**: Permanently failed jobs are preserved

### Monitoring and Metrics

Track queue performance:

- **Job Counts**: Queued, processing, completed, failed
- **Processing Times**: Average job execution duration
- **Failure Rates**: Success/failure percentages
- **Queue Depths**: Backlog monitoring

## Troubleshooting

### Common Issues

#### Redis Connection Failed
```
Error: Redis connection failed
```
**Solutions:**
1. Verify Redis server is running: `redis-cli ping`
2. Check host/port settings in plugin configuration
3. Verify firewall allows Redis connections
4. Test Redis authentication if password is set

#### Jobs Not Processing
```
Jobs remain in 'queued' status
```
**Solutions:**
1. Trigger worker manually via admin interface
2. Check for PHP errors in WordPress error logs
3. Verify job payload is valid JSON
4. Check memory limits and timeouts

#### Memory Exhaustion
```
Fatal error: Allowed memory size exhausted
```
**Solutions:**
1. Reduce batch size in settings
2. Increase PHP memory limit
3. Check for memory leaks in custom jobs
4. Process fewer jobs per worker run

### Debug Mode

Enable debug logging by adding to wp-config.php:

```php
define( 'REDIS_QUEUE_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

### Health Checks

Use the health endpoint to verify system status:

```bash
curl -X GET "https://yoursite.com/wp-json/redis-queue/v1/health"
```

## Performance Optimization

### Redis Configuration

Optimize Redis for queue workloads:

```bash
# redis.conf optimizations
maxmemory-policy allkeys-lru
save 900 1
tcp-keepalive 60
timeout 300
```

### WordPress Configuration

Optimize WordPress for background processing:

```php
// wp-config.php
define( 'WP_MEMORY_LIMIT', '512M' );
define( 'WP_MAX_MEMORY_LIMIT', '512M' );
ini_set( 'max_execution_time', 300 );
```

### Worker Deployment

For production environments, run workers via cron or supervisord:

```bash
# Cron job (runs every minute)
* * * * * /usr/bin/php /path/to/wordpress/wp-cron.php

# Or trigger via WP-CLI
* * * * * wp eval "redis_queue_demo()->process_jobs();"
```

## Security Considerations

### Access Control

- REST API endpoints require `manage_options` capability
- Admin interface restricted to administrators
- Job payloads are sanitized and validated
- SQL injection protection via prepared statements

### Data Protection

- Job payloads stored in database with WordPress security measures
- Redis connection supports password authentication
- Sensitive data should be encrypted before queuing
- Failed job data is preserved for debugging but access-controlled

## License

This plugin is released under the GPL v2 or later license. See the [LICENSE](LICENSE) file for details.

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request

## Support

For support and questions:

- **GitHub Issues**: [Create an issue](https://github.com/persoderlind/redis-queue-demo/issues)
- **WordPress Forums**: [Plugin support forum](https://wordpress.org/support/plugin/redis-queue-demo)
- **Email**: [per@soderlind.no](mailto:per@soderlind.no)

## Changelog

### 1.0.0
- Initial release
- Complete queue system implementation
- Email, image, and API job types
- REST API interface
- WordPress admin interface
- Comprehensive documentation

---

**Made with ❤️ by [Per Søderlind](https://persoderlind.com)**