<?php

declare(strict_types=1);

namespace Catch\Core;

final class Config
{
    /** @var array<string, mixed> */
    private array $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(string $root): self
    {
        $file = $root . '/config/config.ini';
        $values = is_file($file) ? (parse_ini_file($file, true, INI_SCANNER_TYPED) ?: []) : [];
        $map = [
            'APP_ENV' => 'app.env', 'APP_DEBUG' => 'app.debug', 'APP_URL' => 'app.url',
            'APP_KEY' => 'app.key', 'APP_TIMEZONE' => 'app.timezone',
            'PRERELEASE' => 'access.prerelease',
            'PRERELEASE_ALLOWED_SORKOS_USER_ID' => 'access.allowed_sorkos_user_id',
            'DB_DRIVER' => 'database.driver', 'DB_HOST' => 'database.host',
            'DB_PORT' => 'database.port', 'DB_NAME' => 'database.name',
            'DB_USER' => 'database.user', 'DB_PASSWORD' => 'database.password',
            'DB_CHARSET' => 'database.charset', 'SESSION_SECURE' => 'session.secure',
            'UPLOAD_MAX_BYTES' => 'uploads.max_bytes', 'UPLOAD_ALLOWED_MIME' => 'uploads.allowed_mime',
            'SORKOS_BASE_URL' => 'sorkos.base_url', 'SORKOS_CLIENT_ID' => 'sorkos.client_id',
            'SORKOS_CLIENT_SECRET' => 'sorkos.client_secret', 'SORKOS_REDIRECT_URI' => 'sorkos.redirect_uri',
            'SORKOS_POST_LOGOUT_REDIRECT_URI' => 'sorkos.post_logout_redirect_uri',
            'SORKOS_SCOPE' => 'sorkos.scope', 'SORKOS_LANGUAGE' => 'sorkos.language',
            'SORKOS_TLS_VERIFY' => 'sorkos.tls_verify',
        ];
        foreach ($map as $environment => $key) {
            $value = getenv($environment);
            if ($value !== false) {
                [$section, $name] = explode('.', $key, 2);
                $values[$section][$name] = $value;
            }
        }
        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        [$section, $name] = array_pad(explode('.', $key, 2), 2, null);
        return $name === null ? ($this->values[$section] ?? $default) : ($this->values[$section][$name] ?? $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        return filter_var($this->get($key, $default), FILTER_VALIDATE_BOOL);
    }

    public function databaseConfigured(): bool
    {
        return trim((string) $this->get('database.name', '')) !== ''
            && trim((string) $this->get('database.user', '')) !== '';
    }
}
