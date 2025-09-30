# Deferred Mode Implementation

## Overview

Deferred mode allows the REST API endpoint to respond immediately (HTTP 202 Accepted) while processing cron jobs in the background. This prevents timeout issues on large networks and improves integration with external systems.

## Usage

Add the `defer=1` parameter to any REST API call:

```bash
# JSON mode with defer
curl https://example.com/wp-json/all-sites-cron/v1/run?defer=1

# GitHub Actions mode with defer
curl https://example.com/wp-json/all-sites-cron/v1/run?ga=1&defer=1
```

## How It Works

### 1. **Request Flow**

```
Client Request → REST Handler → Immediate Response (HTTP 202)
                                      ↓
                              Background Processing
                                      ↓
                              Cron Jobs Execute
                                      ↓
                              Log Results
```

### 2. **Connection Closure Methods**

The plugin uses different methods based on webserver configuration:

#### FastCGI Method (Preferred)
```php
if ( function_exists( 'fastcgi_finish_request' ) ) {
    // Send response
    echo wp_json_encode( $response->get_data() );
    
    // Close connection
    fastcgi_finish_request();
    
    // Continue processing in background
    all_sites_run_cron_on_all_sites();
}
```

**Supported Environments:**
- Nginx + PHP-FPM ✅
- Apache + mod_fcgid ✅
- Litespeed ✅

#### Fallback Method
```php
// Flush output buffers
ob_start();
echo wp_json_encode( $response->get_data() );
header('Connection: close');
header('Content-Length: ' . ob_get_length());
ob_end_flush();
flush();

// Continue processing
all_sites_run_cron_on_all_sites();
```

**Supported Environments:**
- Apache + mod_php ⚠️ (works but less reliable)
- Most shared hosting ⚠️ (depends on configuration)

## Webserver Compatibility

### ✅ Full Support

| Environment | Method | Notes |
|-------------|--------|-------|
| Nginx + PHP-FPM | FastCGI | Best performance, cleanest connection closure |
| Apache + mod_fcgid | FastCGI | Excellent performance |
| Litespeed | FastCGI | Full support with Litespeed API |

### ⚠️ Partial Support

| Environment | Method | Notes |
|-------------|--------|-------|
| Apache + mod_php | Fallback | Works but connection may not close immediately |
| Shared Hosting | Fallback | Depends on hosting provider's PHP configuration |

### ❌ Not Recommended

| Environment | Issue |
|-------------|-------|
| CGI mode | Cannot close connection early |
| Hosting with aggressive timeouts | May kill long-running processes |

## Testing Your Environment

### 1. Check for FastCGI Support

```bash
php -r "echo function_exists('fastcgi_finish_request') ? 'FastCGI: YES' : 'FastCGI: NO';"
```

### 2. Test Deferred Mode

```bash
# Time the request (should return immediately)
time curl -X GET "https://example.com/wp-json/all-sites-cron/v1/run?defer=1"

# Expected: < 1 second response time
# Actual processing continues in background
```

### 3. Check Background Processing

Monitor your server logs for completion message:

```bash
tail -f /path/to/php-error.log | grep "All Sites Cron"

# Expected output:
# [All Sites Cron] Deferred execution completed: 150 sites processed
```

## Response Examples

### JSON Mode (defer=1)

```json
{
  "success": true,
  "status": "queued",
  "message": "Cron job queued for background processing",
  "timestamp": "2025-10-01 12:00:00",
  "mode": "deferred"
}
```

**HTTP Status:** 202 Accepted

### GitHub Actions Mode (ga=1&defer=1)

```
::notice::Cron job queued for background processing
```

**HTTP Status:** 202 Accepted

### Synchronous Mode (no defer parameter)

```json
{
  "success": true,
  "count": 150,
  "message": "",
  "timestamp": "2025-10-01 12:00:05",
  "endpoint": "rest"
}
```

**HTTP Status:** 200 OK

## Use Cases

### ✅ When to Use Deferred Mode

1. **Large Networks** (100+ sites)
   - Prevents REST API timeouts
   - Allows processing to complete without client waiting

2. **GitHub Actions / CI/CD**
   - Workflow completes quickly
   - No timeout errors
   - Can run more frequently

3. **External Monitoring Services**
   - Services that expect quick responses
   - Pingdom, UptimeRobot, cron-job.org, etc.

4. **Load Balancers**
   - Prevents timeout at load balancer level
   - Better health check responses

### ❌ When NOT to Use Deferred Mode

1. **Small Networks** (< 50 sites)
   - Synchronous mode is fast enough
   - No benefit from deferred processing

2. **Debugging / Testing**
   - Synchronous mode provides immediate feedback
   - Easier to see errors and results

3. **Sequential Dependencies**
   - If you need to wait for completion before next action
   - Synchronous mode ensures completion

## GitHub Actions Configuration

### Recommended Setup

```yaml
name: All Sites Cron Job
on:
  schedule:
    - cron: '*/5 * * * *'  # Every 5 minutes

env:
  CRON_ENDPOINT: 'https://example.com/wp-json/all-sites-cron/v1/run?ga=1&defer=1'

jobs:
  trigger_cron:
    runs-on: ubuntu-latest
    timeout-minutes: 2  # Can be reduced with defer mode
    steps:
      - name: Trigger Cron
        run: |
          curl -X GET ${{ env.CRON_ENDPOINT }} \
            --connect-timeout 10 \
            --max-time 30 \
            --retry 3 \
            --retry-delay 5 \
            --silent \
            --show-error \
            --fail
```

### Why Use Defer Mode in GitHub Actions?

- ✅ Prevents workflow timeout errors
- ✅ Faster workflow completion
- ✅ More reliable execution
- ✅ Can reduce `timeout-minutes` setting
- ✅ Lower GitHub Actions billing (faster = cheaper)

## Performance Comparison

| Mode | Network Size | Response Time | Total Time | Best For |
|------|-------------|---------------|------------|----------|
| Synchronous | 50 sites | 5 seconds | 5 seconds | Small networks |
| Synchronous | 200 sites | 20 seconds | 20 seconds | Medium networks |
| Synchronous | 500 sites | 50 seconds | 50 seconds | Risk of timeout |
| **Deferred** | 50 sites | < 1 second | 5 seconds* | External triggers |
| **Deferred** | 200 sites | < 1 second | 20 seconds* | GitHub Actions |
| **Deferred** | 500 sites | < 1 second | 50 seconds* | Large networks |

\* Total time happens in background; client doesn't wait

## Troubleshooting

### Issue: "Connection not closing"

**Symptom:** Request takes full processing time despite defer=1

**Solutions:**
1. Check if `fastcgi_finish_request()` exists:
   ```bash
   php -r "var_dump(function_exists('fastcgi_finish_request'));"
   ```

2. Verify PHP-FPM is being used:
   ```bash
   php -i | grep "Server API"
   # Should show: FPM/FastCGI
   ```

3. Check for output buffering conflicts in wp-config.php

### Issue: "Process killed during background execution"

**Symptom:** Cron doesn't complete on all sites

**Solutions:**
1. Increase PHP timeout in php.ini:
   ```ini
   max_execution_time = 300
   ```

2. Check for hosting-level process restrictions

3. Consider reducing `all_sites_cron_batch_size` filter

### Issue: "No log output"

**Symptom:** Can't see completion message in logs

**Solutions:**
1. Enable PHP error logging in php.ini:
   ```ini
   log_errors = On
   error_log = /path/to/php-error.log
   ```

2. Check WordPress debug log if WP_DEBUG_LOG is enabled

3. Verify file permissions on log file

## Security Considerations

1. **No Additional Risk:** Deferred mode uses same permissions as synchronous mode
2. **Rate Limiting:** Still applies (60-second default cooldown)
3. **Request Locking:** Prevents concurrent executions
4. **Log Monitoring:** Background execution is logged for auditing

## Future Enhancements

Potential improvements for future versions:

- [ ] Queue system with database storage
- [ ] Status endpoint to check background job progress
- [ ] Webhook callbacks when processing completes
- [ ] Priority queue for specific sites
- [ ] Progress tracking API

## Support

If you encounter issues with deferred mode:

1. Check this documentation first
2. Test your environment compatibility
3. Review server logs for errors
4. Open an issue on GitHub with:
   - PHP version
   - Webserver type and version
   - Hosting environment
   - Error logs
