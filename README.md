# Catch

Catch is a personal capture inbox built with PHP 8.2+, Fat-Free Framework, server-rendered HTML, and a progressively enhanced PWA. PHP's GD extension with WebP support is required for locally stored link previews.

## Local setup

1. Point the web server document root at `public/`.
2. Copy `config/config.example.ini` to `config/config.ini`.
3. Add the remote MariaDB/MySQL credentials.
4. Register a confidential client in Sorkos Login with these exact redirect URIs:
   - `https://catch.test/auth/callback`
   - `https://catch.test/login` (post-logout return)
5. Add the Sorkos client ID and secret to `config/config.ini`. For the local self-signed certificate only, set `tls_verify = false`.
6. Run `php bin/migrate.php`.

Production should keep `tls_verify = true`, use HTTPS, set a strong `app.key`, and expose only `public/`.
The web-server user must have write access to `storage/cache`, `storage/logs`, `storage/sessions`, `storage/tmp`, and `storage/uploads`.

## Prerelease access

Set `access.prerelease = true` and put the one permitted stable Sorkos user ID in `access.allowed_sorkos_user_id`. While enabled, unauthenticated and non-allowlisted browser sessions can only reach `/coming-soon`, login, logout, and the Sorkos callback. API endpoints remain available so paired devices can keep sending captures. Both settings can alternatively be supplied through `PRERELEASE` and `PRERELEASE_ALLOWED_SORKOS_USER_ID`.

## Authentication

Catch redirects the browser to Sorkos `/authorize`, validates the returned state, exchanges the authorization code server-side at `/token`, upserts the local user by the stable public Sorkos user ID, and creates its own session. Local passwords are not stored.

## Useful commands

```text
php bin/migrate.php
composer test
composer cs:check
composer cs:fix
```

## Email-to-Catch importer

Configure the `[mail]` values in `config/config.ini` (or the corresponding `MAIL_*` environment variables), then run the migration. Catch only opens `mail.imap_folder`; the normal inbox is never scanned. Processed messages move to `mail.imap_processed_folder`, valid-address failures move to `mail.imap_failed_folder`, and unknown or revoked addresses are silently deleted.

Run the importer from the project root:

```text
php cli/import-mail.php
```

On ALL-INKL, schedule that command every five minutes. The command uses a non-blocking lock, so overlapping cron invocations exit without processing the same mailbox concurrently. PHP's IMAP and DOM extensions are required. Keep the IMAP password in `config/config.ini` or the host's environment; both `config/config.ini` and `.env` are excluded from source control.

Users create, copy, and revoke private inbound addresses under **Settings → Email**. Catch stores the generated address directly. Its 16-character lowercase Base32 token contains 80 random bits, which keeps addresses compact while remaining impractical to guess through email delivery. Unknown addresses receive no response.

## Command-line client

The read-only Go client lives in [`cli/`](cli/README.md). It supports browser-based device authorization, native OS credential storage, catch-number lookup, search, list, and machine-readable JSON output.

`GET /health` reports application and database availability without exposing credentials.

## Public API reference

The interactive Swagger reference is served at `/docs/api/`; its raw OpenAPI document is available at `/docs/api/openapi.json`. The reference inventories every implemented public machine endpoint, including reserved routes that cannot currently be used with pairing-issued tokens.

Swagger UI 5.32.11 is committed under `public/vendor/swagger-ui/` with its Apache 2.0 license. The page does not load scripts, styles, or validators from a CDN, and Swagger introduces no Composer or production runtime dependency.

Composer is an optional local-development convenience for running tests. Production does not use Composer for application autoloading, migrations, or runtime dependencies.
