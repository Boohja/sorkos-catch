# Catch

Catch is a personal capture inbox built with PHP 8.2+, Fat-Free Framework, server-rendered HTML, and a progressively enhanced PWA.

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

## Prerelease access

Set `access.prerelease = true` and put the one permitted stable Sorkos user ID in `access.allowed_sorkos_user_id`. While enabled, unauthenticated and non-allowlisted browser sessions can only reach `/coming-soon`, login, logout, and the Sorkos callback. API endpoints remain available so paired devices can keep sending captures. Both settings can alternatively be supplied through `PRERELEASE` and `PRERELEASE_ALLOWED_SORKOS_USER_ID`.

## Authentication

Catch redirects the browser to Sorkos `/authorize`, validates the returned state, exchanges the authorization code server-side at `/token`, upserts the local user by the stable public Sorkos user ID, and creates its own session. Local passwords are not stored.

## Useful commands

```text
php bin/migrate.php
composer test
```

`GET /health` reports application and database availability without exposing credentials.

Composer is an optional local-development convenience for running tests. Production does not use Composer for application autoloading, migrations, or runtime dependencies.
