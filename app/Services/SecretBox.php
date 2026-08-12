<?php

declare(strict_types=1);

namespace Catch\Services;

use Catch\Core\Config;

final class SecretBox
{
    private readonly string $key;

    public function __construct(Config $config)
    {
        $material = (string)$config->get('app.key', '');
        if (strlen($material) < 32) {
            throw new \RuntimeException('app.key must contain at least 32 characters.');
        }
        $this->key = sodium_crypto_generichash($material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $encoded): string
    {
        $payload = base64_decode($encoded, true);
        if ($payload === false || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Encrypted value is invalid.');
        }
        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if ($plain === false) {
            throw new \RuntimeException('Encrypted value could not be decrypted.');
        }
        return $plain;
    }
}
