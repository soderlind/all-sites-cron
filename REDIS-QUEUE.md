# Redis Queue for All Sites Cron

Most likely [you don't need this](#summary), use [FastCGI connection-close instead](https://github.com/soderlind/all-sites-cron/blob/main/DEFERRED-MODE.md).

```php
// If using ?defer=1, and don't want to use Redis
add_filter( 'all_sites_cron_use_redis_queue', '__return_false' );
```


## Overview

When deferred mode (`?defer=1`) is enabled, All Sites Cron can automatically use Redis as a job queue if Redis is available. This provides a more robust and scalable solution compared to the FastCGI connection-close method.

## Benefits of Redis Queue

- **Reliability**: Jobs are persisted in Redis and won't be lost if the web server restarts
- **Scalability**: Multiple worker processes can consume jobs from the queue
- **Visibility**: You can monitor queue length and processing status
- **Decoupling**: Web requests complete instantly while workers process jobs independently
- **Retry Logic**: Failed jobs can be easily re-queued (when implemented)

## Requirements

1. **Redis Extension**: The PHP Redis extension must be installed and loaded
   ```bash
   # Check if Redis extension is available
   php -m | grep redis
   ```

2. **Redis Server**: A running Redis server (local or remote)
   ```bash
   # Check Redis connection
   redis-cli ping
   # Should return: PONG
   ```

## How It Works

### 1. Job Queuing (REST API Request)

When you call the endpoint with `?defer=1`:

```bash
curl "https://example.com/wp-json/all-sites-cron/v1/run?defer=1"
```

**If Redis is available:**
- Job is pushed to Redis queue (`all_sites_cron:jobs`)
- Immediate HTTP 202 response: `"Cron job queued to Redis for background processing"`
- Lock is released immediately
- No processing happens in the web request

**If Redis is NOT available:**
- Falls back to FastCGI connection-close method
- Processing happens in the web request (after connection close)

### 2. Job Processing (Worker)

You need a separate worker process to consume jobs from the queue:

```bash
# Process one job from the queue
curl -X POST "https://example.com/wp-json/all-sites-cron/v1/process-queue"
```

## Setup Instructions

### Option 1: Using WordPress Object Cache (Recommended)

If you're already using Redis for WordPress object caching (e.g., Redis Object Cache plugin), the plugin will automatically use the existing Redis connection.

No additional configuration needed!

### Option 2: Direct Redis Connection

If you're not using Redis for object caching, configure the connection in `wp-config.php`:

```php
// Redis connection settings for All Sites Cron
add_filter( 'all_sites_cron_redis_host', function() {
    return '127.0.0.1'; // Redis host
});

add_filter( 'all_sites_cron_redis_port', function() {
    return 6379; // Redis port
});

add_filter( 'all_sites_cron_redis_db', function() {
    return 0; // Redis database number
});
```

### Option 3: Disable Redis Queue

To force the FastCGI method even if Redis is available:

```php
add_filter( 'all_sites_cron_use_redis_queue', '__return_false' );
```

## Worker Setup

You need to set up a worker process to consume jobs from the Redis queue. Here are several options:

### Option 1: System Cron (Recommended)

Run the worker every minute to process pending jobs:

```bash
# Edit crontab
crontab -e

# Add this line (runs every minute)
* * * * * curl -X POST -s https://example.com/wp-json/all-sites-cron/v1/process-queue >> /var/log/all-sites-cron-worker.log 2>&1
```

### Option 2: Systemd Service (Production)

Create a systemd service for continuous processing:

```ini
# /etc/systemd/system/all-sites-cron-worker.service
[Unit]
Description=All Sites Cron Worker
After=network.target redis.service

[Service]
Type=simple
User=www-data
ExecStart=/usr/bin/php -r "while(true) { file_get_contents('https://example.com/wp-json/all-sites-cron/v1/process-queue', false, stream_context_create(['http' => ['method' => 'POST']])); sleep(60); }"
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

```bash
# Enable and start the service
sudo systemctl enable all-sites-cron-worker
sudo systemctl start all-sites-cron-worker

# Check status
sudo systemctl status all-sites-cron-worker
```

### Option 3: WP-CLI Script

Create a PHP script to run with WP-CLI:

```php
<?php
// worker.php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    exit( 'This script can only be run via WP-CLI' );
}

WP_CLI::line( 'Starting All Sites Cron worker...' );

while ( true ) {
    $response = wp_remote_post( home_url( '/wp-json/all-sites-cron/v1/process-queue' ) );
    
    if ( is_wp_error( $response ) ) {
        WP_CLI::warning( 'Error: ' . $response->get_error_message() );
    } else {
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        WP_CLI::line( sprintf( 'Processed: %s', $body['message'] ?? 'Unknown' ) );
    }
    
    sleep( 60 ); // Wait 60 seconds before next check
}
```

Run it:
```bash
wp eval-file worker.php
```

## GitHub Actions Setup

Queue jobs via GitHub Actions and let workers process them:

```yaml
name: Queue All Sites Cron (Redis)
on:
  schedule:
    - cron: '*/5 * * * *'  # Every 5 minutes

env:
  CRON_ENDPOINT: 'https://example.com/wp-json/all-sites-cron/v1/run?defer=1'

jobs:
  queue_job:
    runs-on: ubuntu-latest
    timeout-minutes: 1  # Very fast since we're just queuing
    steps:
      - name: Queue cron job to Redis
        run: |
          curl -X GET ${{ env.CRON_ENDPOINT }} \
            --connect-timeout 5 \
            --max-time 10 \
            --silent \
            --show-error \
            --fail
```

## Monitoring

### Check Queue Length

```bash
# Using redis-cli
redis-cli LLEN all_sites_cron:jobs

# Using PHP
php -r '$redis = new Redis(); $redis->connect("127.0.0.1"); echo "Queue length: " . $redis->lLen("all_sites_cron:jobs") . "\n";'
```

### View Jobs in Queue

```bash
# View all jobs (without removing them)
redis-cli LRANGE all_sites_cron:jobs 0 -1
```

### Clear Queue

```bash
# Emergency: clear all pending jobs
redis-cli DEL all_sites_cron:jobs
```

### Monitor WordPress Logs

Check your WordPress error log for processing information:

```bash
tail -f /path/to/wordpress/wp-content/debug.log | grep "All Sites Cron"
```

## Customization

### Custom Queue Key

Change the Redis queue key name:

```php
add_filter( 'all_sites_cron_redis_queue_key', function( $key ) {
    return 'my_custom_queue_name';
});
```

## Troubleshooting

### Redis Not Detected

**Problem**: Redis is installed but not detected by the plugin.

**Solution**: Check if the Redis extension is loaded:
```bash
php -m | grep redis
```

Install if missing:
```bash
# Ubuntu/Debian
sudo apt-get install php-redis

# CentOS/RHEL
sudo yum install php-redis

# Using PECL
sudo pecl install redis
```

### Jobs Not Processing

**Problem**: Jobs are queued but never processed.

**Solution**: Make sure you have a worker running (see Worker Setup section above).

Check queue length:
```bash
redis-cli LLEN all_sites_cron:jobs
```

If the number keeps growing, your worker isn't running or isn't processing fast enough.

### Connection Refused

**Problem**: `Redis connection failed: Connection refused`

**Solution**: 
1. Check if Redis is running: `redis-cli ping`
2. Verify connection settings (host/port)
3. Check firewall rules if Redis is on a remote server

### Worker Timing Out

**Problem**: Worker process times out before completing.

**Solution**: Increase PHP execution time for the worker or reduce batch size:

```php
add_filter( 'all_sites_cron_batch_size', function() {
    return 25; // Process fewer sites per batch
});
```

## Performance Comparison

### FastCGI Method (No Redis)
- ✅ No additional infrastructure required
- ✅ Works immediately
- ⚠️ Limited by PHP-FPM configuration
- ⚠️ Jobs lost if server restarts during processing
- ⚠️ No visibility into job status

### Redis Queue Method
- ✅ Extremely fast response times (< 10ms)
- ✅ Jobs persisted and reliable
- ✅ Scalable with multiple workers
- ✅ Full visibility and monitoring
- ⚠️ Requires Redis server
- ⚠️ Requires separate worker process

## Best Practices

1. **Monitor Queue Length**: Set up alerts if queue length exceeds a threshold
2. **Worker Redundancy**: Run multiple workers for reliability
3. **Batch Size Tuning**: Adjust `all_sites_cron_batch_size` based on your network size
4. **Rate Limiting**: Keep rate limiting enabled to prevent queue flooding
5. **Log Monitoring**: Regularly check logs for errors or slow processing
6. **Graceful Degradation**: The plugin automatically falls back to FastCGI if Redis fails

## Security Notes

- The `/process-queue` endpoint is public (like `/run`)
- Rate limiting and locking still apply to prevent abuse
- Consider adding authentication if exposing to the internet
- Use Redis authentication (`requirepass`) in production

## Summary

Redis queue support makes deferred mode more robust and scalable:

1. **Enable deferred mode**: Add `?defer=1` to your endpoint URL
2. **Redis auto-detection**: Plugin automatically uses Redis if available
3. **Set up worker**: Use cron, systemd, or WP-CLI to process the queue
4. **Monitor**: Check queue length and logs regularly

For most users, the FastCGI method works great. Use Redis queue if you have:
- Very large networks (500+ sites)
- High-frequency scheduling (< 1 minute)
- Need for job persistence and monitoring
- Multiple web servers (distributed architecture)
