=== All Sites Cron ===
Contributors: PerS
Tags: cron, multisite, wp-cron
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run wp-cron on all public sites in a multisite network (REST API based). Formerly known as DSS Cron.

== Description ==

All Sites Cron (formerly DSS Cron) runs wp-cron across every public site in a multisite network in a single, non‑overlapping dispatch using a lightweight REST endpoint.

> Why not just a shell loop + WP-CLI? Race conditions and overlapping cron executions across many sites become noisy and slow. This plugin centralizes dispatch safely and quickly.


== Installation ==

1. Download [`all-sites-cron.zip`](https://github.com/soderlind/all-sites-cron/releases/latest/download/all-sites-cron.zip)
2. Upload via Network > Plugins > Add New > Upload Plugin
3. Network Activate the plugin.
4. Disable WordPress default cron in `wp-config.php`:
   ```php
   define( 'DISABLE_WP_CRON', true );
   ```

Plugin updates are handled automatically via GitHub. No need to manually download and install updates.

= Configuration =

The plugin exposes a REST API endpoint that triggers cron jobs across your network.

Usage (JSON): `https://example.com/wp-json/all-sites-cron/v1/run`

GitHub Actions format: `https://example.com/wp-json/all-sites-cron/v1/run?ga=1`

Adding `?ga=1` to the URL outputs results in GitHub Actions compatible format:
- Success: `::notice::Running wp-cron on X sites`
- Error: `::error::Error message`



= Trigger Options =

1. System Crontab (every 5 minutes):

`
*/5 * * * * curl -s https://example.com/wp-json/all-sites-cron/v1/run
`

2. GitHub Actions (every 5 minutes):

`
name: All Sites Cron Job
on:
  schedule:
    - cron: '*/5 * * * *'

env:
  CRON_ENDPOINT: 'https://example.com/wp-json/all-sites-cron/v1/run?ga=1'

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
`

== Customization ==

Adjust maximum sites processed per request (default: 200):

```
add_filter( 'all_sites_cron_number_of_sites', function( $sites_per_request ) {
  return 200; // or a lower number if you have very large networks
});
```

Adjust sites cache (transient) duration (default: 1 hour):

```
add_filter( 'all_sites_cron_sites_transient', function( $duration ) {
  return 30 * MINUTE_IN_SECONDS; // cache list for 30 minutes
});
```

Rate limit (cooldown) between runs (default: 60 seconds):

```
add_filter( 'all_sites_cron_rate_limit_seconds', function() { return 120; });
```

Request timeout per spawned site cron (default: 0.01):

```
add_filter( 'all_sites_cron_request_timeout', function() { return 0.05; });
```

Legacy filters `dss_cron_*` still work; prefer the new `all_sites_cron_*` names.

== Changelog ==

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
* For `wp_remote_get, set sslverify to false. This will allow the request to be non-blocking and not wait for the response.

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

It registers a REST route (`/wp-json/all-sites-cron/v1/run`) that, when requested, dispatches non‑blocking cron spawn requests (`wp-cron.php`) to each public site. It uses a very short timeout and fire‑and‑forget semantics similar to core so the central request returns quickly.

= Why rate limiting? =

To prevent excessive overlapping runs triggered by external schedulers (e.g., multiple GitHub Action retries). You can tune or disable via the filter.

= Is the old namespace still available? =

Yes, `dss-cron/v1` remains temporarily as an alias. Migrate to `all-sites-cron/v1` soon; the alias will be removed in a future major release.

= Can I still use the old filters? =

Yes, legacy `dss_cron_*` filters proxy to the new ones for backward compatibility.

== Screenshots ==

1. No screenshots available.


== License ==

This plugin is licensed under the GPL2 license. See the [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html) file for more information.