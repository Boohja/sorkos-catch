<?php

declare(strict_types=1);

namespace Catch\Services;

use Catch\Core\Config;
use Catch\Repositories\UserRepository;
use RuntimeException;

final class AuthService
{
    public function __construct(private readonly UserRepository $users, private readonly Config $config)
    {
    }

    public function authorizationUrl(): string
    {
        $this->assertConfigured();
        $state = bin2hex(random_bytes(32));
        $_SESSION['sorkos_oauth_state'] = $state;
        return rtrim((string)$this->config->get('sorkos.base_url'), '/') . '/authorize?' . http_build_query([
            'client_id' => $this->config->get('sorkos.client_id'),'redirect_uri' => $this->config->get('sorkos.redirect_uri'),
            'response_type' => 'code','state' => $state,'scope' => $this->config->get('sorkos.scope', 'profile email'),
            'lang' => $this->config->get('sorkos.language', 'de'),
        ], arg_separator:'&', encoding_type:PHP_QUERY_RFC3986);
    }

    public function complete(string $code, string $state): array
    {
        $expected = (string)($_SESSION['sorkos_oauth_state'] ?? '');
        unset($_SESSION['sorkos_oauth_state']);
        if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
            throw new RuntimeException('The authorization state is invalid.');
        }
        $identity = $this->exchange($code);
        if (empty($identity['id'])) {
            throw new RuntimeException('Sorkos did not return a user identity.');
        }
        if (!empty($identity['email']) && empty($identity['email_verified'])) {
            throw new RuntimeException('The Sorkos email address is not verified.');
        }
        return $this->users->upsertFromSorkos($identity);
    }

    public function establishSession(string $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['_csrf'] = bin2hex(random_bytes(24));
    }

    public function user(): ?array
    {
        return isset($_SESSION['user_id']) ? $this->users->find((string)$_SESSION['user_id']) : null;
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public function logoutUrl(): string
    {
        $this->assertConfigured();
        return rtrim((string)$this->config->get('sorkos.base_url'), '/') . '/logout?' . http_build_query([
            'client_id' => $this->config->get('sorkos.client_id'),
            'redirect_uri' => $this->config->get('sorkos.post_logout_redirect_uri', $this->config->get('sorkos.redirect_uri')),
        ], arg_separator:'&', encoding_type:PHP_QUERY_RFC3986);
    }

    public function configured(): bool
    {
        return trim((string)$this->config->get('sorkos.client_id', '')) !== '' && trim((string)$this->config->get('sorkos.client_secret', '')) !== '';
    }

    private function exchange(string $code): array
    {
        if ($code === '') {
            throw new RuntimeException('The authorization code is missing.');
        }
        $this->assertConfigured();

        $verify = $this->config->bool('sorkos.tls_verify', true);
        $curl = curl_init(rtrim((string) $this->config->get('sorkos.base_url'), '/') . '/token');
        if ($curl === false) {
            throw new RuntimeException('Sorkos token exchange could not be initialized.');
        }

        $configured = curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $this->config->get('sorkos.client_id'),
                'client_secret' => $this->config->get('sorkos.client_secret'),
                'redirect_uri' => $this->config->get('sorkos.redirect_uri'),
            ]),
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ]);
        if (!$configured) {
            throw new RuntimeException('Sorkos token exchange could not be configured.');
        }

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $transportError = curl_error($curl);

        // CurlHandle is released automatically; the legacy close helper is deprecated in PHP 8.5.
        $curl = null;

        if ($body === false) {
            throw new RuntimeException('Sorkos token exchange transport failed: ' . ($transportError ?: 'unknown cURL error'));
        }

        $decoded = json_decode((string) $body, true);
        if ($status !== 200) {
            $providerError = is_array($decoded)
                ? trim((string) ($decoded['error_description'] ?? $decoded['error'] ?? ''))
                : '';
            throw new RuntimeException(sprintf(
                'Sorkos token exchange failed with HTTP %d%s.',
                $status,
                $providerError !== '' ? ': ' . $providerError : '',
            ));
        }
        if (!is_array($decoded) || !is_array($decoded['user'] ?? null)) {
            throw new RuntimeException('Sorkos returned an invalid response.');
        }

        return $decoded['user'];
    }

    private function assertConfigured(): void
    {
        if (!$this->configured()) {
            throw new RuntimeException('Sorkos client credentials have not been configured.');
        }
    }
}
