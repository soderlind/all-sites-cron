# Redis Queue - Quick Start Guide

## What is it?

When you use deferred mode (`?defer=1`), the plugin can now automatically queue jobs to Redis instead of processing them immediately. This makes it more reliable and scalable.

## Do I need Redis?

**No!** Redis is completely optional. The plugin will:
- ✅ Use Redis if it's available
- ✅ Fall back to the FastCGI method if Redis is not available
- ✅ Work exactly as before if you don't have Redis

## How do I enable it?

**Nothing!** If Redis is installed and running, it's automatic.

## Quick Test

### 1. Check if you have Redis:
```bash
php -m | grep redis
redis-cli ping
```

### 2. Queue a job:
```bash
curl "https://example.com/wp-json/all-sites-cron/v1/run?defer=1"
```

If Redis is available, you'll see:
```json
{
  "success": true,
  "status": "queued",
  "message": "Cron job queued to Redis for background processing",
  "mode": "redis"
}
```

### 3. Set up a worker (cron job):
```bash
# Add to crontab (runs every minute)
* * * * * curl -X POST -s https://example.com/wp-json/all-sites-cron/v1/process-queue
```

That's it!

## When should I use Redis?

Use Redis if you have:
- ✅ Large networks (500+ sites)
- ✅ High-frequency scheduling
- ✅ Need for job persistence
- ✅ Multiple web servers

Otherwise, the FastCGI method works great!

## Configuration (Optional)

If Redis is not on localhost:

```php
// wp-config.php
add_filter( 'all_sites_cron_redis_host', fn() => 'redis.example.com' );
add_filter( 'all_sites_cron_redis_port', fn() => 6379 );
```

To disable Redis even if available:

```php
add_filter( 'all_sites_cron_use_redis_queue', '__return_false' );
```

## More Information

See [REDIS-QUEUE.md](REDIS-QUEUE.md) for complete documentation.
