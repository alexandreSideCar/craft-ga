<!-- craftcms-claude-skills -->
# Architecture

- Business logic in services. Controllers are thin: validate, delegate, respond.
- `AnalyticsService` owns all Google API communication (GA4 Data API, Search Console). Controllers and widgets never call the Google client directly.
- Services extend `yii\base\Component`, registered via `static config()` in `Plugin.php`.
- Settings live in the `Settings` model; persist OAuth tokens and config through it, not in ad-hoc storage.
- Configure the Guzzle/Google client with explicit timeouts — never let an external API call hang a CP request.
- Cache expensive API lookups; invalidate when credentials or property selection change.
- Fire before/after events on significant operations to keep the plugin extensible.
- Events, permissions, widget, and CP routes are all registered from `Plugin::init()` via `Event::on(...)`.
