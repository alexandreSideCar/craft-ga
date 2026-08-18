# Changelog

All notable changes to Craft GA are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 2.1.0 - 2026-08-18

### Added

- New **Top Referrals (30 days)** panel on the dashboard, listing the top
  referring sources (e.g. `lapresse.ca`) by sessions with a change indicator
  vs the previous 30 days. Sessions are filtered to GA4's "Referral" default
  channel group, so search engines, direct traffic, and social networks don't
  crowd out true referrers.

## 2.0.1 - 2026-08-18

### Fixed

- A transient network failure or Google outage during token refresh no longer
  wipes the stored connection. The plugin now disconnects only when Google
  definitively rejects the refresh token (`invalid_grant`); transient errors
  keep the tokens and surface a "try again later" message instead of forcing a
  full reconnect.
- Every Google API call now has an explicit timeout (15s for GA4 report calls
  and for all Search Console requests, token refresh included), so an
  unreachable Google API can no longer hang a control panel request on the
  clients' unbounded or ~60s defaults.
- A cache duration of `0` no longer caches API responses forever. Yii treats a
  duration of 0 as "never expire", so the setting now enforces a minimum of 1
  second, and a `0` stored by an older install is clamped at runtime.
- API error responses are now cached for only 30 seconds instead of the full
  cache duration, so a brief API failure no longer pins the error banner to the
  dashboard for the whole cache window.

## 2.0.0 - 2026-08-18

This release renames the plugin from **Analytics** to **Craft GA**. The plugin
handle changed, which makes this a breaking release. Read the upgrade steps
before updating.

### Why

The previous handle, `craft-analytics`, was also claimed by
[coyshdigital/craft-analytics](https://github.com/Coysh-Digital/craft-analytics).
Craft keys plugin registration, control panel routes, settings storage,
permissions, and translation categories by handle, so two plugins sharing one
handle cannot be installed on the same site. Renaming resolves the collision.

### Changed

- **Handle** is now `craft-ga` (was `craft-analytics`).
- **Composer package** is now `sidecar/craft-ga` (was `sidecar/craft-analytics`).
- **PHP namespace** is now `sidecar\craftga` (was `sidecar\craftanalytics`).
- **Display name** is now "Craft GA" in the CP navigation, the section page
  title, and the dashboard widget. Previously the navigation item and widget
  both read "Analytics", which was indistinguishable from the other plugin.
- **Permissions** are now `craft-ga:view` and `craft-ga:manage`, listed under a
  "Craft GA" heading in the user group settings (previously "Analytics").
- **Control panel URLs** now live under `/craft-ga` instead of `/craft-analytics`.
- **OAuth redirect URI** is now `<cp-url>/craft-ga/oauth/callback`.
- Translation files renamed to `craft-ga.php`; the translation category passed
  to `Craft::t()` and the `|t` filter is now `craft-ga`.
- Cache keys are now prefixed `craft-ga:` and tagged `craft-ga`.

### Breaking

- **Plugin settings are not carried over.** Craft stores plugin settings in the
  `plugins` table keyed by handle. Because the handle changed, Craft treats this
  as a different plugin: the previous install record is orphaned and its stored
  settings — including the GA4 property ID, Search Console configuration, and
  OAuth client credentials and tokens — are not read. A migration cannot recover
  them, because migrations only run for the plugin under its new handle.
- **You must reconnect Google.** The stored OAuth access and refresh tokens are
  part of the orphaned settings, so the Google connection must be re-established.
- **The redirect URI changed.** The old URI is no longer valid, so authorization
  will fail until the new one is registered in Google Cloud Console.
- **Granted permissions are not carried over.** Any user groups previously
  granted `craft-analytics:view` or `craft-analytics:manage` must be re-granted
  the new `craft-ga:*` permissions.
- **Custom template overrides and code references break.** Anything referencing
  the old handle, namespace, or CP path needs updating — including
  `craft.app.plugins.getPlugin('craft-analytics')` calls, template overrides
  under `templates/craft-analytics/`, and imports of `sidecar\craftanalytics\*`.

### Upgrading from 1.x

Before you start, record your current GA4 property ID, Search Console site URL,
and OAuth client ID and secret from **Settings → Plugins → Analytics**. You will
re-enter them. The client ID and secret can also be retrieved from Google Cloud
Console, but the property ID is quicker to copy now.

1. Uninstall the old plugin so it does not leave a stale record and a duplicate
   nav item:

   ```bash
   php craft plugin/uninstall craft-analytics
   composer remove sidecar/craft-analytics
   ```

2. Install the renamed plugin:

   ```bash
   composer require sidecar/craft-ga
   php craft plugin/install craft-ga
   ```

3. Open **Settings → Plugins → Craft GA**. The settings screen displays the
   exact **Authorized Redirect URI** for your site under the Google OAuth2
   section — copy it from there rather than constructing it by hand, since it
   depends on your CP trigger. Add it to your OAuth 2.0 client in Google Cloud
   Console under **Authorized redirect URIs**.

   You may remove the old `/craft-analytics/oauth/callback` URI once the upgrade
   is confirmed working.

4. On the same settings screen, re-enter the GA4 property ID, OAuth client ID,
   and client secret, then save.

5. Click **Connect to Google** and complete the authorization flow.

6. Re-grant permissions under **Settings → Users → User Groups** for any group
   that previously had access. The permissions are now listed as
   **View Analytics** and **Manage Analytics** under the **Craft GA** heading.

7. Update any custom code or template overrides that referenced the old handle
   or namespace.

If you were only ever running this plugin and not the Coysh Digital one, no data
in GA4 or Search Console is affected — this is a local configuration change only.
Nothing is deleted from your Google account, and no analytics history is lost.

## 1.0.10 - 2026-07-16

### Changed

- Updated `google/analytics-data` to `^0.24` and `google/apiclient-services` to `^0.450`.

## 1.0.9 - 2026-06-18

### Added

- Claude Code project configuration.

## 1.0.8 - 2026-03-16

### Added

- Key event page details.
- Search Console variation badges.

### Changed

- Tables are now responsive.

## 1.0.7 - 2026-03-16

### Added

- Retry logic for expired OAuth tokens.
- Explicit Guzzle timeout so a slow Google API call cannot hang a CP request.

### Fixed

- Lookup performance in `AnalyticsService`.
- Incorrect type hints.

## 1.0.6 - 2026-03-13

### Fixed

- Dashboard grid alignment, baseline, and pane title margins.

## 1.0.5 - 2026-03-13

### Fixed

- Redirect URI no longer includes query parameters added by Craft, which Google
  rejected.
- Lightswitch toggle behaviour in settings.

## 1.0.4 - 2026-03-13

### Fixed

- Disconnect button.
- OAuth settings fields and redirect URLs.

## 1.0.3 - 2026-03-13

### Added

- Google Search Console documentation.

## 1.0.2 - 2026-03-13

### Changed

- Updated plugin icon.

## 1.0.1 - 2026-03-10

### Added

- README with installation and setup instructions.

## 1.0.0 - 2026-03-10

Initial release.
