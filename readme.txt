=== DSS Cron ===
Contributors: PerS
Tags: cron, multisite, wp-cron
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run wp-cron on all public sites in a multisite network (now via REST API route).

== Description ==

DSS Cron is a WordPress plugin designed to run wp-cron on all public sites in a multisite network. This ensures that scheduled tasks are executed across all sites in the network.

> "You could have done this with a simple cron job. Why use this plugin?" I have a cluster of WordPress sites. I did run a shell script calling wp cli, but the race condition was a problem. I needed a way to run wp-cron on all sites without overlapping. This plugin was created to solve that problem. 


== Installation ==

1. Upload the `dss-cron` folder to the `/wp-content/plugins/` directory.
2. Network activate the plugin through the 'Network->Plugins' menu in WordPress.
3. Disable WordPress default cron in `wp-config.php`:
   ```php
   define('DISABLE_WP_CRON', true);
   ```

= Configuration =

The plugin exposes a REST API endpoint that triggers cron jobs across your network.

Usage (JSON): `https://example.com/wp-json/dss-cron/v1/run`

GitHub Actions format: `https://example.com/wp-json/dss-cron/v1/run?ga=1`

Adding `?ga=1` to the URL outputs results in GitHub Actions compatible format:
- Success: `::notice::Running wp-cron on X sites`
- Error: `::error::Error message`



= Trigger Options =

1. System Crontab (every 5 minutes):

`
*/5 * * * * curl -s https://example.com/wp-json/dss-cron/v1/run
`

2. GitHub Actions (every 5 minutes):

`
name: DSS Cron Job
on:
  schedule:
    - cron: '*/5 * * * *'

env:
  CRON_ENDPOINT: 'https://example.com/wp-json/dss-cron/v1/run?ga=1'

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

= Customization =

Adjust maximum sites processed per request (default: 200):

`
add_filter('dss_cron_number_of_sites', function($sites_per_request) {
	return 200;
});
`

Adjust sites cache duration (default: 1 hour):

`
add_filter('dss_cron_sites_transient', function($duration) {
	return HOUR_IN_SECONDS * 2; // 2 hours
});
`

== Changelog ==

= 1.2.0 =
* Switch to REST API route: `/wp-json/dss-cron/v1/run` (old /dss-cron endpoint removed)
* Keep `?ga=1` for GitHub Actions plaintext output
* Simplify activation (no rewrite flush)
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
* Set the number of sites to 200. You can use the `add_filter( 'dss_cron_number_of_sites', function() { return 100; } );` to change the number of sites per request.

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

The plugin hooks into a custom endpoint to run the cron job. It adds a rewrite rule and tag for the endpoint `dss-cron`. When this endpoint is accessed, the plugin will run wp-cron on all public sites in the multisite network.

== Screenshots ==

1. No screenshots available.

== License ==

This plugin is licensed under the GPL2 license. See the [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html) file for more information.