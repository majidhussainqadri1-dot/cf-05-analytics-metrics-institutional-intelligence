<?php

declare(strict_types=1);

namespace Sabri\AnalyticsIntelligence\Infrastructure;

final class CryptoBox
{
    private string $key;

    public function __construct(string $key)
    {
        if (strlen($key) < 32) {
            throw new \InvalidArgumentException('Encryption key must be at least 32 bytes.');
        }
        $this->key = hash('sha256', $key, true);
    }

    public function encrypt(string $plaintext, string $context = ''): string
    {
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($context . "\0" . $plaintext, $nonce, $this->key);
            return (string) wp_json_encode([
                'v' => 1,
                'alg' => 'secretbox',
                'n' => base64_encode($nonce),
                'c' => base64_encode($cipher),
            ], JSON_UNESCAPED_SLASHES);
        }

        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(
            $context . "\0" . $plaintext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $context
        );
        if (!is_string($cipher)) {
            throw new \RuntimeException('Encryption failed.');
        }

        return (string) wp_json_encode([
            'v' => 1,
            'alg' => 'aes-256-gcm',
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'c' => base64_encode($cipher),
        ], JSON_UNESCAPED_SLASHES);
    }

    public function decrypt(string $envelope, string $context = ''): string
    {
        $data = json_decode($envelope, true);
        if (!is_array($data) || (int) ($data['v'] ?? 0) !== 1 || !is_string($data['alg'] ?? null)) {
            throw new \RuntimeException('Invalid encrypted envelope.');
        }

        if ($data['alg'] === 'secretbox') {
            if (!function_exists('sodium_crypto_secretbox_open')) {
                throw new \RuntimeException('Sodium is unavailable.');
            }
            $nonce = base64_decode((string) ($data['n'] ?? ''), true);
            $cipher = base64_decode((string) ($data['c'] ?? ''), true);
            if (!is_string($nonce) || !is_string($cipher)) {
                throw new \RuntimeException('Invalid secretbox envelope.');
            }
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
            if (!is_string($plain)) {
                throw new \RuntimeException('Decryption failed.');
            }
        } elseif ($data['alg'] === 'aes-256-gcm') {
            $iv = base64_decode((string) ($data['iv'] ?? ''), true);
            $tag = base64_decode((string) ($data['tag'] ?? ''), true);
            $cipher = base64_decode((string) ($data['c'] ?? ''), true);
            if (!is_string($iv) || !is_string($tag) || !is_string($cipher)) {
                throw new \RuntimeException('Invalid AES envelope.');
            }
            $plain = openssl_decrypt(
                $cipher,
                'aes-256-gcm',
                $this->key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $context
            );
            if (!is_string($plain)) {
                throw new \RuntimeException('Decryption failed.');
            }
        } else {
            throw new \RuntimeException('Unsupported encryption algorithm.');
        }

        $prefix = $context . "\0";
        if (!str_starts_with($plain, $prefix)) {
            throw new \RuntimeException('Encrypted context mismatch.');
        }
        return substr($plain, strlen($prefix));
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }
}
