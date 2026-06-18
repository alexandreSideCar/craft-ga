<!-- craftcms-claude-skills v1.2.0 -->
# Analytics — Craft CMS 5 Plugin

Google Analytics 4 dashboard for Craft CMS. Provides a CP section, a dashboard widget, and a service that talks to the GA4 Data API and Search Console via the Google API client.

@.claude/rules/coding-style.md
@.claude/rules/architecture.md
@.claude/rules/git-workflow.md
@.claude/rules/scaffolding.md
@.claude/rules/security.md

## General

Be critical. We're equals — push back when something doesn't make sense.

Do not excessively use emojis. Do not include AI attribution in commits, PRs, issues, or comments — output should be indistinguishable from human-authored work.

Do not include "Test plan" sections in PR descriptions.

## Tools

Run `composer` and `craft` directly on the host — this plugin has no DDEV environment of its own.

```bash
composer install                     # Install deps
composer dump-autoload               # Refresh autoloader after adding classes
```

When testing against a host Craft site, run `craft` commands from that site's root (e.g. `php craft up`, `php craft clear-caches/all`), not from this plugin directory.

Use `gh` for all GitHub operations — it's already authenticated.

> No ECS, PHPStan, or Pest is configured. If you add them later, wire the scripts into `composer.json` and update this file.

## Plugin Structure

```
src/
├── Plugin.php                   # Entry point — registers service, widget, permissions, CP routes
├── controllers/
│   └── AnalyticsController.php   # CP web controller (OAuth flow, data endpoints)
├── models/
│   └── Settings.php             # Plugin settings (OAuth credentials, property IDs)
├── services/
│   └── AnalyticsService.php     # GA4 Data API + Search Console business logic
├── widgets/
│   └── AnalyticsWidget.php      # Dashboard widget
├── templates/
│   └── _settings.twig           # CP settings template
├── icon.svg / icon-mask.svg     # CP section icons
└── translations/                # (add when introducing translated strings)
```

## Available Skills

These Craft CMS skills are installed and load automatically by trigger:

- **`craftcms`** — extending Craft (services, controllers, widgets, events, permissions)
- **`craft-php-guidelines`** — PHP coding standards for Craft plugins
- **`craft-garnish`** — CP JavaScript / Garnish (if you add CP asset bundles)
- **`craft-twig-guidelines`** — CP Twig template conventions

## Documentation

- Plugin development: https://craftcms.com/docs/5.x/extend/
- Class reference: https://docs.craftcms.com/api/v5/
- Generator: https://craftcms.com/docs/5.x/extend/generator.html
- Craft source: `vendor/craftcms/cms/src/`
- GA4 Data API: https://developers.google.com/analytics/devguides/reporting/data/v1
