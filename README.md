# DSS Cron

Run wp-cron on all public sites in a multisite network

> "You could have done this with a simple cron job. Why use this plugin?" I have a cluster of WordPress sites. I did run a shell script calling wp cli, but the race condition was a problem. I needed a way to run wp-cron on all sites without overlapping. This plugin was created to solve that problem.

## 🚀 Quick Start

1. Upload `dss-cron` to `/wp-content/plugins/`
2. Network activate via 'Network->Plugins'
3. Disable WordPress default cron in `wp-config.php`:
   ```php
   define( 'DISABLE_WP_CRON', true );
   ```

Also available via Composer:

```bash
composer require soderlind/dss-cron
```

## 🔧 Configuration

The plugin creates an endpoint at /dss-cron that triggers cron jobs across your network.

Usage: `https://example.com/dss-cron`

Adding ?ga to the URL (e.g., `https://example.com/dss-cron?ga`) will output results in GitHub Actions compatible format:

- Success: `::notice::Running wp-cron on X sites`
- Error: `::error::Error message`

  Example GitHub Action success notice:

  <img src="assets/ga-output.png" alt="GitHub Action - Success notice" style="with: 60%">

## ⏰ Trigger Options

1. System Crontab (every 5 minutes):

```bash
*/5 * * * * curl -s https://example.com/dss-cron
```

2. GitHub Actions (every 5 minutes. 5 minutes is the [shortest interval in GitHub Actions](https://docs.github.com/en/actions/writing-workflows/choosing-when-your-workflow-runs/events-that-trigger-workflows#schedule)):

```yaml
name: DSS Cron Job
on:
  schedule:
    - cron: '*/5 * * * *'

env:
  CRON_ENDPOINT: 'https://example/dss-cron/?ga'

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

## Customization

Adjust maximum sites processed per request (default: 200):

```php
add_filter( 'dss_cron_number_of_sites', function( $sites_per_request ) {
	return 200;
});
```

Adjust transient expiration time (default: 1 hour):

```php
add_filter( 'dss_cron_transient_expiration', function( $expiration ) {
	return HOUR_IN_SECONDS;
});
```

## Copyright and License

DSS Cron is copyright 2024 Per Soderlind

DSS Cron is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 2 of the License, or (at your option) any later version.

DSS Cron is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU Lesser General Public License along with the Extension. If not, see http://www.gnu.org/licenses/.
