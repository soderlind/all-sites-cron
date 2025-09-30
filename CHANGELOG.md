## ⚙️ Changelog

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
