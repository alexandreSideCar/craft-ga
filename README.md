# Craft GA

Google Analytics 4 dashboard plugin for Craft CMS 5.

![Packagist Version](https://img.shields.io/packagist/v/sidecar/craft-ga)
![License](https://img.shields.io/packagist/l/sidecar/craft-ga)

## Features

- **Dashboard overview** — Users, sessions, and page views for today, 7 days, and 30 days with percentage comparison vs previous period
- **Daily traffic chart** — Line chart showing users, sessions, and page views over the last 30 days
- **Top pages** — Most visited pages with title, path, views, and trend
- **Traffic sources** — Channel breakdown with doughnut chart
- **Key events** — GA4 key events (conversions) with counts and trends
- **Engagement stats** — Average session duration, bounce rate, pages per session, new vs returning users
- **Google Search Console** — Search performance data including queries, pages, clicks, impressions, CTR, and average position
- **Dashboard widget** — Compact summary widget for the Craft dashboard
- **Permissions** — Granular user permissions (View Analytics / Manage Analytics)
- **Translations** — English and French included
- **Caching** — Reports served from cache and refreshed in the background (configurable duration)
- **OAuth2** — Secure connection via Google OAuth2 (no service account JSON needed)

## Requirements

- Craft CMS 5.3+
- PHP 8.2+
- A Google Cloud project with the **Google Analytics Data API** and **Google Search Console API** enabled
- A GA4 property
- A Search Console property (optional, for Search Console features)

## Installation

```bash
composer require sidecar/craft-ga
php craft plugin/install craft-ga
```

Or install via the Craft CMS Plugin Store.

### Upgrading from 1.x

Version 2.0.0 renamed this plugin from `sidecar/craft-analytics` to
`sidecar/craft-ga` and changed its handle. Because Craft keys plugin settings by
handle, upgrading does not carry your settings over — you will need to reconnect
Google and re-enter your GA4 property ID. See the
[upgrade steps in the changelog](CHANGELOG.md#200---2026-08-18).

## Google Cloud Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create or select a project
3. Enable the **Google Analytics Data API** and the **Google Search Console API** (APIs & Services > Library)
4. Create OAuth 2.0 credentials:
   - Go to APIs & Services > Credentials
   - Click **Create Credentials** > **OAuth client ID**
   - Application type: **Web application**
   - Add the **Authorized redirect URI** shown in the plugin settings page
5. Copy the **Client ID** and **Client Secret**

## Plugin Configuration

1. Go to **Settings > Plugins > Analytics** in the Craft CP
2. Enter your **GA4 Property ID** (numeric ID from GA4 Admin > Property Settings, e.g. `123456789`)
3. Enter your **OAuth Client ID** and **OAuth Client Secret**
4. Click **Save**
5. Click **Connect to Google** and authorize access
6. Back on the settings page, copy the refresh token line shown once into your `.env` file
   (`GA4_OAUTH_REFRESH_TOKEN="..."`) and reload the page
7. You're connected — visit the **Analytics** section in the CP sidebar

The plugin never stores the refresh token itself: the setting only holds a
`$GA4_OAUTH_REFRESH_TOKEN` reference, so the secret stays out of
`config/project/` and version control. Access tokens are short-lived and are
derived from the refresh token on the fly, so nothing token-related is persisted.

### Environment Variables

All settings support environment variables:

```env
GA4_PROPERTY_ID=123456789
GA4_OAUTH_CLIENT_ID=your-client-id.apps.googleusercontent.com
GA4_OAUTH_CLIENT_SECRET=your-client-secret
GA4_OAUTH_REFRESH_TOKEN=issued-by-connect-to-google
```

Then in plugin settings, use `$GA4_PROPERTY_ID`, `$GA4_OAUTH_CLIENT_ID`,
`$GA4_OAUTH_CLIENT_SECRET` and `$GA4_OAUTH_REFRESH_TOKEN`.

Each environment needs its own `GA4_OAUTH_REFRESH_TOKEN`; a token issued while
connecting from one environment can be reused in another as long as the OAuth
client is the same.

## Permissions

The plugin registers two permissions under **Settings > Users**:

| Permission | Description |
|---|---|
| **View Analytics** | Access the Analytics CP page and dashboard widget |
| **Manage Analytics** | Clear cache and refresh data (nested under View) |

Admin users always have full access. OAuth connection and plugin settings are restricted to admins only.

## Cache

Reports are cached and served instantly. A report stays fresh for 5 minutes by
default (change it with the Cache Duration setting). After that it is still
shown immediately from the cache while a queue job refreshes it in the
background, so the dashboard never waits on Google once it has loaded once.
Only a report that was never cached, or whose cached copy is more than 24 hours
old, is fetched while the page loads. If a background refresh fails, the last
good copy keeps being shown.

Click **Clear Cache & Refresh** on the Analytics page to drop every cached
report and force a fresh fetch.

### Queue and cron

No plugin-specific cron job is required, but the background refresh runs as a
Craft queue job, so the queue has to be processed:

- **Default setup** (`runQueueAutomatically` left at `true`): Craft runs queued
  jobs on control panel requests. Nothing to configure.
- **Queue runner** (`runQueueAutomatically` set to `false`, as is common in
  production): make sure your queue worker is running, for example
  `php craft queue/listen` under a process manager, or `php craft queue/run`
  from cron. Without it, stale reports are never refreshed.

A cron job is still worthwhile on low-traffic sites. Reports are refreshed only
when someone opens the dashboard, so the first visit after a quiet period shows
older data until the queue job finishes, and after 24 hours without a visit the
page has to fetch synchronously again. Keeping the cache warm avoids both:

```bash
# Refresh every report, every 10 minutes
*/10 * * * * cd /path/to/craft-project && php craft craft-ga/cache/warm
```

The command exits with a non-zero status and lists the failing reports if
Google cannot be reached, so cron monitoring picks up outages. Run it from your
Craft project root, not from this plugin's directory. `php craft
craft-ga/cache/clear` empties the cache from the command line.

## Translations

The plugin ships with English and French translations. Translation files are located in `src/translations/`.

## License

MIT

## Credits

Developed by [Side-Car](https://side-car.ca/).
