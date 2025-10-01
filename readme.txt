=== All Sites Cron ===
Contributors: PerS
Tags: cron, multisite, wp-cron,redis
Requires at least: 6.7
Tested up to: 6.8
Stable tag: 1.5.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run wp-cron on all public sites in a multisite network (REST API based). Formerly known as DSS Cron.

== Description ==

All Sites Cron (formerly DSS Cron) runs wp-cron across every public site in a multisite network in a single, non‑overlapping dispatch using a lightweight REST endpoint.

> Why not just a shell loop + WP-CLI? Race conditions and overlapping cron executions across many sites become noisy and slow. This plugin centralizes dispatch safely and quickly.

= Configuration =

The plugin exposes a REST API endpoint that triggers cron jobs across your network.

Usage (JSON):
`https://example.com/wp-json/all-sites-cron/v1/run`

GitHub Actions format:
`https://example.com/wp-json/all-sites-cron/v1/run?ga=1`

Deferred mode (responds immediately, processes in background):
`https://example.com/wp-json/all-sites-cron/v1/run?defer=1`

Combine parameters:
`https://example.com/wp-json/all-sites-cron/v1/run?ga=1&defer=1`

Adding `?ga=1` to the URL outputs results in GitHub Actions compatible format:
- Success: `::notice::Running wp-cron on X sites`
- Error: `::error::Error message`

Deferred mode (`?defer=1`) returns HTTP 202 immediately and processes in background. Ideal for large networks (100+ sites) and GitHub Actions to prevent timeout errors. Works best with Nginx + PHP-FPM, Apache + mod_fcgid, and most modern hosting.

**Redis Queue Support**: If Redis is available, deferred mode automatically queues jobs to Redis for more reliable and scalable background processing. Falls back to FastCGI method if Redis is not available. No configuration needed - it just works!

= Documentation =

- [Plugin Homepage](https://github.com/soderlind/all-sites-cron)
- [Triggering Options](https://github.com/soderlind/all-sites-cron#-trigger-options)
- [Filters](https://github.com/soderlind/all-sites-cron#filters)
- [Deferred Mode](https://github.com/soderlind/all-sites-cron/blob/main/DEFERRED-MODE.md)
- [Redis Quick Start](https://github.com/soderlind/all-sites-cron/blob/main/REDIS-QUICK-START.md)
- [Redis Queue](https://github.com/soderlind/all-sites-cron/blob/main/REDIS-QUEUE.md)

== Installation ==

1. Download all-sites-cron.zip from https://github.com/soderlind/all-sites-cron/releases/latest/download/all-sites-cron.zip
2. Upload via Network > Plugins > Add New > Upload Plugin
3. Network Activate the plugin.
4. Disable WordPress default cron in `wp-config.php`:

`define( 'DISABLE_WP_CRON', true );`

Plugin updates are handled automatically via GitHub. No need to manually download and install updates.


== Changelog ==

= 1.5.3 =
* Enhanced function documentation with detailed parameter and return specifications
* Added configuration constants for better maintainability (timeouts, batch sizes, cooldowns)
* Improved error handling with structured error codes for debugging
* Enhanced logging with detailed execution status and error tracking
* Better type safety with improved type hints and consistent return types
* Comprehensive code quality improvements and documentation enhancements
* Replaced magic numbers with named constants for configuration values

= 1.5.2 =
* Fix links in readme.txt, to point to correct documentation files.

= 1.5.1 =
* Add links to docs from readme.txt

= 1.5.0 =
* Add Redis queue support for deferred mode - automatic if Redis is available
* Jobs queued to Redis (`all_sites_cron:jobs`) for reliable background processing
* New `/process-queue` endpoint for worker processes to consume Redis jobs
* Automatic Redis detection - uses Redis if available, falls back to FastCGI if not
* Improved reliability - jobs persisted in Redis won't be lost if server restarts
* Supports multiple worker processes for high-volume networks
* Queue length and job status can be monitored via Redis
* Configuration filters for Redis host, port, database, and queue key
* Comprehensive Redis documentation (REDIS-QUEUE.md and REDIS-QUICK-START.md)
* Fully backward compatible - Redis is optional, existing setups work unchanged

= 1.4.1 =
* Code refactoring: Removed redundant `all_sites_cron_` prefix from function names (namespace provides isolation)
* Improved code maintainability by eliminating DRY violations
* Extracted helper functions for REST route registration, response formatting, and lock management
* Cleaner function names: `register_rest_routes()`, `rest_run()`, `create_response()`, `acquire_lock()`, etc.
* Better code organization with centralized rate limiting, error handling, and lock cleanup
* No functionality changes - pure code refactoring for maintainability

= 1.4.0 =
* Add deferred mode with `defer=1` parameter for immediate response and background processing
* Support for FastCGI (`fastcgi_finish_request()`) on Nginx + PHP-FPM and Apache + mod_fcgid
* Fallback method for Apache mod_php and other configurations
* Return HTTP 202 (Accepted) in deferred mode
* Ideal for large networks (100+ sites) to prevent REST API timeouts
* Optimized for GitHub Actions and CI/CD pipelines
* Add comprehensive webserver compatibility documentation

= 1.3.2 =
* Documentation updates and readme.txt formatting fixes

= 1.3.1 =
* Fix SQL preparation security issue
* Add proper REST API parameter sanitization
* Implement request locking to prevent concurrent executions
* Add comprehensive error logging
* Implement batch processing for large networks (default: 50 sites per batch)
* Add new `all_sites_cron_batch_size` filter
* Add return type hints for better code quality
* Properly register activation and deactivation hooks
* Add `uninstall.php` for complete cleanup on plugin deletion
* Update filter documentation in README
* Remove `all_sites_cron_sites_transient` filter (no longer needed with batch processing)
* Change default max sites from 200 to 1000

= 1.3.0 =
* Rename plugin to All Sites Cron (formerly DSS Cron)
* New REST namespace `all-sites-cron/v1` (legacy `dss-cron/v1` kept temporarily)
* Add one-time cleanup removing old `dss_cron_*` transients
* Introduce new filter names `all_sites_cron_*` with backward compatibility

= 1.2.0 =
* Switch to REST API route: `/wp-json/dss-cron/v1/run` (old /dss-cron endpoint removed)
* Keep `?ga=1` for GitHub Actions plaintext output
* Internal refactor / cleanup

= 1.1.0 =
* Add JSON response format (default) for `/dss-cron` (use `?ga` for GitHub Actions plain text output)
* Non-blocking fire-and-forget cron dispatch retained and refined
* Prevent canonical 301 redirects for the endpoint
* Internal refactor / cleanup

= 1.0.12 =
* Refactor error message handling

= 1.0.11 =
* Maintenance update

= 1.0.10 =
* Added GitHub Actions output format when using ?ga parameter

= 1.0.9 =
* Add sites caching using transients to improve performance.

= 1.0.8 =
* Update documentation

= 1.0.7 =
* Set the number of sites to 200. (Historical note: original example used `dss_cron_number_of_sites`; current filter name is `all_sites_cron_number_of_sites`. Example: `add_filter( 'all_sites_cron_number_of_sites', fn() => 100 );`)

= 1.0.6 =
* Make plugin faster by using `$site->__get( 'siteurl' )` instead of `get_site_url( $site->blog_id )`. This prevents use of `switch_to_blog()` and `restore_current_blog()` functions. They are expensive and slow down the plugin.
* For `wp_remote_get`, set `blocking` to `false`. This will allow the request to be non-blocking and not wait for the response.
* For `wp_remote_get`, set `sslverify` to `false`. This will allow the request to be non-blocking and not wait for the response.

= 1.0.5 =
* Update composer.json with metadata

= 1.0.4 =
* Add namespace
* Tested up to WordPress 6.7
* Updated plugin description with license information.

= 1.0.3 =
* Fixed version compatibility

= 1.0.2 =
* Updated plugin description and tested up to version.

= 1.0.1 =
* Initial release.

== Frequently Asked Questions ==

= How does the plugin work? =

It registers a REST route (`/wp-json/all-sites-cron/v1/run`) that, when requested, dispatches non-blocking cron spawn requests (`wp-cron.php`) to each public site. It uses a very short timeout and fire-and-forget semantics similar to core so the central request returns quickly.

= Why rate limiting? =

To prevent excessive overlapping runs triggered by external schedulers (e.g., multiple GitHub Action retries). You can tune or disable via the filter.

= Is the old namespace still available? =

Yes, `dss-cron/v1` remains temporarily as an alias. Migrate to `all-sites-cron/v1` soon; the alias will be removed in a future major release.

= Can I still use the old filters? =

Yes, legacy `dss_cron_*` filters proxy to the new ones for backward compatibility.

== Screenshots ==

== License ==

This plugin is licensed under the GPL2 license. See the [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html) file for more information.