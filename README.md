# All Sites Cron

Run wp-cron on all public sites in a multisite network ([REST API based](#benefits-of-rest-mode)).

> "You could have done this with a simple cron job. Why use this plugin?" 
> 
> I have a cluster of WordPress sites. I did run a shell script calling wp cli, but the race condition was a problem. I needed a way to run wp-cron on all sites without overlapping. This plugin was created to solve that problem.

## 🚀 Quick Start

1. Download [`all-sites-cron.zip`](https://github.com/soderlind/all-sites-cron/releases/latest/download/all-sites-cron.zip)
2. Upload via `Network > Plugins > Add New > Upload Plugin`
3. Network Activate the plugin.
4. Disable WordPress default cron in `wp-config.php`:
   ```php
   define( 'DISABLE_WP_CRON', true );
   ```

Also available via Composer:

```bash
composer require soderlind/all-sites-cron
```

**Updates**
   * Plugin updates are handled automatically via GitHub. No need to manually download and install updates.

## 🔧 Configuration

The plugin exposes a REST API route that triggers cron across your network.

JSON usage:

```
https://example.com/wp-json/all-sites-cron/v1/run
```

GitHub Actions plain text (add `?ga=1`):

```
https://example.com/wp-json/all-sites-cron/v1/run?ga=1
```

[Deferred mode](DEFERRED-MODE.md) (add `?defer=1` - responds immediately, processes in background):

```
https://example.com/wp-json/all-sites-cron/v1/run?defer=1
```

**🚀 Redis Queue Support**: If Redis is available, deferred mode automatically uses Redis for job queuing (more reliable and scalable). See [Redis Queue documentation](REDIS-QUEUE.md) or [Quick Start](REDIS-QUICK-START.md).

Combine parameters (GitHub Actions + Deferred):

```
https://example.com/wp-json/all-sites-cron/v1/run?ga=1&defer=1
```

Adding `?ga=1` outputs results in GitHub Actions compatible format:

- Success: `::notice::Running wp-cron on X sites`
- Error: `::error::Error message`

<details>

  <summary><strong>Example GitHub Action success notice</strong></summary>

  <img src="assets/ga-output.png" alt="GitHub Action - Success notice" style="with: 60%">
</details>

## ⏰ Trigger Options

1. (Preferred) Use a service like cron-job.org, pingdom.com, or easycron.com to call the endpoint every 5 minutes.

2. System Crontab (every 5 minutes):

```bash
*/5 * * * * curl -s https://example.com/wp-json/all-sites-cron/v1/run
```

3. GitHub Actions (every 5 minutes. 5 minutes is the [shortest interval in GitHub Actions](https://docs.github.com/en/actions/writing-workflows/choosing-when-your-workflow-runs/events-that-trigger-workflows#schedule)):

2. GitHub Actions (every 5 minutes):

```yaml
name: All Sites Cron Job
on:
  schedule:
    - cron: '*/5 * * * *'

env:
  CRON_ENDPOINT: 'https://example.com/wp-json/all-sites-cron/v1/run?ga=1&defer=1'

jobs:
  trigger_cron:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - run: |
          curl -X GET ${{ env.CRON_ENDPOINT }} \
            --connect-timeout 10 \
            --max-time 30 \
            --retry 3 \
            --retry-delay 5 \
            --silent \
            --show-error \
            --fail
```

**Note:** Using `defer=1` is recommended for GitHub Actions to prevent timeout errors on large networks.

## Customization

### Filters

The plugin provides several filters to customize its behavior:

#### `all_sites_cron_rate_limit_seconds`

Control the cooldown period between cron runs to prevent overlapping executions.

- **Type**: `int`
- **Default**: `60` (seconds)
- **Legacy**: `dss_cron_rate_limit_seconds` (still supported)

```php
add_filter( 'all_sites_cron_rate_limit_seconds', function( $seconds ) {
    return 120; // 2 minutes between runs
});
```

#### `all_sites_cron_number_of_sites`

Set the maximum number of sites to process in total per request.

- **Type**: `int`
- **Default**: `1000`
- **Legacy**: `dss_cron_number_of_sites` (still supported)

```php
add_filter( 'all_sites_cron_number_of_sites', function( $max_sites ) {
    return 500; // Process up to 500 sites
});
```

#### `all_sites_cron_batch_size`

Control how many sites are processed in each batch. Smaller batches use less memory.

- **Type**: `int`
- **Default**: `50`
- **New in**: v1.3.0

```php
add_filter( 'all_sites_cron_batch_size', function( $batch_size ) {
    return 25; // Process 25 sites per batch
});
```

#### `all_sites_cron_request_timeout`

Set the timeout for wp-cron HTTP requests to each site. Uses "fire and forget" (non-blocking) requests.

- **Type**: `float`
- **Default**: `0.01` (10 milliseconds)
- **Legacy**: `dss_cron_request_timeout` (still supported)

```php
add_filter( 'all_sites_cron_request_timeout', function( $timeout ) {
    return 0.05; // 50 milliseconds
});
```

#### `https_local_ssl_verify`

WordPress core filter to control SSL verification for local requests.

- **Type**: `bool`
- **Default**: `false` (in plugin context)
- **Core Filter**: This is a WordPress core filter

```php
add_filter( 'https_local_ssl_verify', function( $verify ) {
    return true; // Enable SSL verification
});
```

### Filter Usage Examples

**Large Network Configuration** (1000+ sites):

```php
// Process more sites in smaller batches
add_filter( 'all_sites_cron_number_of_sites', fn() => 2000 );
add_filter( 'all_sites_cron_batch_size', fn() => 25 );
add_filter( 'all_sites_cron_rate_limit_seconds', fn() => 180 ); // 3 minutes
```

**Small Network Configuration** (< 100 sites):

```php
// Faster processing with larger batches
add_filter( 'all_sites_cron_batch_size', fn() => 100 );
add_filter( 'all_sites_cron_rate_limit_seconds', fn() => 30 ); // 30 seconds
```

**Development/Testing Configuration**:

```php
// More aggressive settings for testing
add_filter( 'all_sites_cron_rate_limit_seconds', fn() => 10 ); // 10 seconds
add_filter( 'all_sites_cron_batch_size', fn() => 5 );
add_filter( 'all_sites_cron_request_timeout', fn() => 0.1 );
```

### Legacy Filters

The following legacy filters from the "DSS Cron" plugin are still supported but deprecated:

- `dss_cron_rate_limit_seconds` → Use `all_sites_cron_rate_limit_seconds`
- `dss_cron_number_of_sites` → Use `all_sites_cron_number_of_sites`
- `dss_cron_request_timeout` → Use `all_sites_cron_request_timeout`
- `dss_cron_sites_transient` → No longer used (removed in v1.3.0)

**Migration**: Update your code to use the new `all_sites_cron_*` filter names. Legacy filters will be removed in a future major version.

### Interpreting Rate Limiting

If called again before the cooldown finishes the API returns HTTP 429 with JSON:

```json
{
  "success": false,
  "error": "rate_limited",
  "message": "Rate limited. Try again in 37 seconds.",
  "retry_after": 37,
  "cooldown": 60,
  "last_run_gmt": 1696071234,
  "timestamp": "2025-09-30 12:35:23"
}
```

Headers include: `Retry-After: <seconds>`.


## Benefits of REST mode

- No rewrite rules to flush: activation is simpler and avoids edge cases with 404s or delayed availability.
- No unexpected 301 canonical/trailing‑slash redirects: direct, cache‑friendly 200 responses.
- Versioned, discoverable endpoint (`/wp-json/all-sites-cron/v1/run`) integrates with the WP REST index and tooling.
- Consistent structured JSON by default plus optional GitHub Actions text via `?ga=1`.
- Proper HTTP status codes (e.g. 429 for rate limiting, 400 for invalid context) instead of a blanket 200.
- Easy extensibility: future endpoints (status, logs, defer mode, auth) can be added under the same namespace without new rewrites.
- Reduced theme / front‑end interference: bypasses template loading and front‑end filters tied to `template_redirect`.
- Better compatibility with CDNs and monitoring: REST semantics and headers are predictable and cache‑aware.
- Straightforward integration in external systems (CI/CD, orchestration) that already speak JSON.
- Built‑in argument handling and potential for schema/permission hardening via `permission_callback`.
- Clean separation of concerns: routing (REST) vs. execution logic (cron dispatcher) improves maintainability.
- Clear place to implement enhancements (rate limiting, future defer/background mode, auth tokens, metrics) with minimal risk.
- Easier automated testing using WP REST API test utilities (no need to simulate front‑end rewrite resolution).
- Avoids canonical redirect filter hacks previously needed to suppress 301s on `/dss-cron`.
- Safer for multi‑environment deployments (no dependency on rewrite flush timing during deploy pipelines).

## Copyright and License

All Sites Cron is copyright 2024 Per Soderlind

All Sites Cron is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 2 of the License, or (at your option) any later version.

All Sites Cron is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU Lesser General Public License along with the Extension. If not, see http://www.gnu.org/licenses/.

---

### Migration Note

The plugin was renamed from "DSS Cron" (slug: `dss-cron`) to "All Sites Cron" (slug: `all-sites-cron`). The old REST namespace `dss-cron/v1` is still registered for backward compatibility, but you should migrate your automation scripts to use `all-sites-cron/v1`. Legacy WordPress filters like `dss_cron_number_of_sites` continue to work; new code should use the `all_sites_cron_*` equivalents.
