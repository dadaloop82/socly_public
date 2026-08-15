<?php

declare(strict_types=1);

namespace Socly\Core;

final class Encryptor
{
    public function __construct(private readonly string $key)
    {
        if (strlen($this->rawKey()) !== 32) {
            throw new \InvalidArgumentException('APP_KEY must decode to 32 bytes.');
        }
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    private function rawKey(): string
    {
        $key = $this->key;
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            return $decoded === false ? '' : $decoded;
        }
        return $key;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->rawKey(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16
        );
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return base64_encode($nonce . $tag . $cipher);
    }

    public function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('Invalid ciphertext.');
        }
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            $this->rawKey(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed.');
        }
        return $plain;
    }
}
