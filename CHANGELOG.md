## ⚙️ Changelog

### 1.5.1 - 2025-10-01

- Add links to docs from readme.txt

### 1.5.0 - 2025-10-01

- **Feature**: Added [Redis queue support](REDIS-QUEUE.md) for deferred mode - automatic if Redis is available.
- **Enhancement**: Jobs are queued to Redis (`all_sites_cron:jobs`) for more reliable background processing.
- **Enhancement**: New `/process-queue` endpoint for worker processes to consume Redis jobs.
- **Enhancement**: Automatic Redis detection - uses Redis if available, falls back to FastCGI method if not.
- **Enhancement**: Improved reliability - jobs persisted in Redis won't be lost if server restarts.
- **Scalability**: Supports multiple worker processes for high-volume networks.
- **Monitoring**: Queue length and job status can be monitored via Redis.
- **Configuration**: Filters for Redis host, port, database, and queue key name.
- **Documentation**: Comprehensive Redis queue documentation with setup instructions and examples.
- **Backward Compatible**: Works with existing deferred mode - Redis is optional.

### 1.4.1 - 2025-10-02

- **Code Quality**: Major refactoring to eliminate DRY (Don't Repeat Yourself) violations.
- **Maintainability**: Removed redundant `all_sites_cron_` prefix from all functions since namespace provides isolation.
- **Refactoring**: Cleaner function names: `register_rest_routes()`, `rest_run()`, `create_response()`, `acquire_lock()`, `release_lock()`, etc.
- **Refactoring**: Extracted helper functions for REST route registration, response formatting, lock management, and rate limiting.
- **Refactoring**: Consolidated duplicate code into reusable functions: `get_rest_args()`, `create_response()`, `get_filter()`.
- **Refactoring**: Added dedicated lock management functions: `acquire_lock()` and `release_lock()`.
- **Refactoring**: Created `check_rate_limit()` function to centralize rate limiting logic.
- **Refactoring**: Added `execute_and_cleanup()` wrapper for proper error handling and lock cleanup.
- **Readability**: Significantly improved code clarity by leveraging namespace and removing redundant prefixes.
- **Note**: No functionality changes - pure code refactoring for maintainability.

### 1.4.0 - 2025-10-01

- **Feature**: Added [deferred mode](DEFERRED-MODE.md) (`?defer=1`) for immediate response with background processing.
- **Enhancement**: Supports FastCGI (`fastcgi_finish_request()`) for Nginx + PHP-FPM, Apache + mod_fcgid.
- **Enhancement**: Fallback method for Apache mod_php and other configurations.
- **Enhancement**: Returns HTTP 202 (Accepted) in deferred mode to indicate async processing.
- **Performance**: Ideal for large networks (100+ sites) to avoid REST API timeouts.
- **Compatibility**: Full support for modern hosting environments with PHP-FPM.
- **Documentation**: Comprehensive deferred mode documentation with webserver compatibility details.
- **Use Case**: Optimized for GitHub Actions and CI/CD pipelines.

### 1.3.2 - 2025-09-30

- **Documentation**: Fixed readme.txt formatting to comply with WordPress.org standards.
- **Documentation**: Added proper backticks to all code examples and inline code references.
- **Documentation**: Comprehensive filter documentation with practical examples in README.md.

### 1.3.1 - 2025-09-30

- **Security**: Fixed SQL preparation to use proper parameterized queries.
- **Security**: Implemented request locking mechanism to prevent concurrent executions and race conditions.
- **Enhancement**: Added REST API parameter sanitization with `rest_sanitize_boolean`.
- **Enhancement**: Implemented batch processing for large networks (default batch size: 50 sites).
- **Enhancement**: Added comprehensive error logging for debugging and monitoring.
- **Enhancement**: Added return type hints to all functions for better code quality.
- **Enhancement**: Properly registered activation and deactivation hooks with cleanup functionality.
- **Enhancement**: Created `uninstall.php` for complete data cleanup when plugin is deleted.
- **Filter**: Added new `all_sites_cron_batch_size` filter to control batch processing size.
- **Documentation**: Comprehensive filter documentation added to README with practical examples.
- **Breaking**: Removed `all_sites_cron_sites_transient` filter (sites are no longer cached, processed in batches instead).
- **Default**: Changed default `all_sites_cron_number_of_sites` from 200 to 1000.

### 1.3.0 - 2025-09-30

- Rename plugin from `DSS Cron` (slug: `dss-cron`) to `All Sites Cron` (slug: `all-sites-cron`).
- Register new REST namespace `all-sites-cron/v1`; keep `dss-cron/v1` as deprecated alias for backward compatibility.
- Introduce new filter names `all_sites_cron_*` while supporting legacy `dss_cron_*` filters.
- One-time migration deletes legacy `dss_cron_*` site transients on first load.

### 1.2.0

- Replace custom rewrite /dss-cron endpoint with REST API route: `GET /wp-json/dss-cron/v1/run`.
- Preserve GitHub Actions plain text output via `?ga=1` query param.
- Simplify activation (no rewrite flush needed).
- Internal refactor: removed template_redirect logic.
- Documentation updated to reflect REST usage.
- Add rate limiting (HTTP 429) with filter `dss_cron_rate_limit_seconds` (default 60s) and `Retry-After` header.

### 1.1.0

- Add JSON response format (default) for `/dss-cron` endpoint; plain text output retained only when using the `?ga` parameter for GitHub Actions formatting.
- Maintain non‑blocking (fire‑and‑forget) cron dispatch with ultra‑short timeout for faster responses.
- Suppress canonical 301 redirects for the endpoint to ensure consistent 200 status.
- Internal refactors / cleanup.

### 1.0.12

- Refactor error message handling

### 1.0.11

- Maintenance update

### 1.0.10

- Added GitHub Actions output format when using ?ga parameter

### 1.0.9

- Add sites caching using transients to improve performance.

### 1.0.8

- Update documentation.

### 1.0.7

- Set the number of sites to 200. You can use the `add_filter( 'dss_cron_number_of_sites', function() { return 100; } );` to change the number of sites per request.

### 1.0.6

- Make plugin faster by using `$site->__get( 'siteurl' )` instead of `get_site_url( $site->blog_id )`. This prevents use of `switch_to_blog()` and `restore_current_blog()` functions. They are expensive and slow down the plugin.
- For `wp_remote_get`, set `blocking` to `false`. This will allow the request to be non-blocking and not wait for the response.
- For `wp_remote_get`, set `sslverify` to `false`. This will allow the request to be non-blocking and not wait for the response.

### 1.0.5

- Update composer.json with metadata

### 1.0.4

- Add namespace
- Tested up to WordPress 6.7
- Updated plugin description with license information.

### 1.0.3

- Fixed version compatibility

### 1.0.2

- Updated plugin description and tested up to version.

### 1.0.1

- Initial release.
