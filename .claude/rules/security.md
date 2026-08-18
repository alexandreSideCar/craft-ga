<!-- craftcms-claude-skills -->
# Security

- `$this->requirePostRequest()` on every mutating controller action.
- `$this->requireAdmin()` for actions that change plugin settings or initiate the OAuth flow.
- `$this->requirePermission('craft-ga:view')` (or the relevant permission) on data-read actions exposed to non-admins.
- OAuth client secrets and tokens: store via the `Settings` model / `App::env()`. Never hardcode credentials, never commit them, never log them.
- Treat the OAuth access/refresh tokens as sensitive — keep them out of error messages, debug output, and PR diffs.
- Validate `state` and redirect URIs in the OAuth callback; only accept the redirect URI you generated.
- All user input through `Db::parseParam()` / `Db::parseDateParam()` — never raw SQL interpolation. The same applies to values passed into GA4 API filters.
- No secrets in CLAUDE.md, committed files, or log output.
