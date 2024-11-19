=== DSS Cron ===
Contributors: PerS
Tags: cron, multisite, wp-cron
Requires at least: 5.0
Tested up to: 6.3.5
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run wp-cron on all public sites in a multisite network.

== Description ==

DSS Cron is a WordPress plugin designed to run wp-cron on all public sites in a multisite network. This ensures that scheduled tasks are executed across all sites in the network.

== Installation ==

1. Upload the `dss-cron` folder to the `/wp-content/plugins/` directory.
2. Network activate the plugin through the 'Network->Plugins' menu in WordPress.
3. The plugin will automatically add a custom rewrite rule and tag for the cron endpoint.

== Changelog ==

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