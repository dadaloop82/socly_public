<?php

declare(strict_types=1);

namespace Socly\Core\Addon;

use SodiumException;

/**
 * A signed licence token (Ed25519) verified locally, without any network call.
 *
 * Format: SOCLY-ENT1.<base64url(json payload)>.<base64url(signature)>
 * The signature covers "SOCLY-ENT1.<payload segment>", so the prefix cannot be swapped.
 */
final class Entitlement
{
    public const PREFIX = 'SOCLY-ENT1';

    /** @param array<string, mixed> $payload */
    private function __construct(
        private readonly array $payload,
        private readonly string $raw
    ) {
    }

    /**
     * Verify a token with the embedded public key. Null when the token is malformed,
     * the signature is invalid or the payload is not usable.
     */
    public static function verify(string $token): ?self
    {
        $token = trim($token);
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            return null;
        }
        [$prefix, $segment, $signature] = $parts;
        $json = AddonKeys::b64uDecode($segment);
        $signatureBytes = AddonKeys::b64uDecode($signature);
        $publicKey = AddonKeys::publicKey('license');
        if ($json === '' || $signatureBytes === '' || $publicKey === '') {
            return null;
        }
        try {
            if (!sodium_crypto_sign_verify_detached($signatureBytes, $prefix . '.' . $segment, $publicKey)) {
                return null;
            }
        } catch (SodiumException) {
            return null;
        }
        $payload = json_decode($json, true);
        if (!is_array($payload) || (int) ($payload['v'] ?? 0) !== 1) {
            return null;
        }
        if (trim((string) ($payload['lic'] ?? '')) === '' || !is_array($payload['addons'] ?? null)) {
            return null;
        }
        $payload['addons'] = self::normalizeAddons($payload['addons']);

        return new self($payload, $token);
    }

    /**
     * Sign a payload (used by the site/tooling; instances only verify).
     *
     * @param array<string, mixed> $payload
     */
    public static function issue(array $payload, string $privateKey): string
    {
        $payload['v'] = (int) ($payload['v'] ?? 1);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('Cannot encode the entitlement payload.');
        }
        $segment = AddonKeys::b64u($json);
        $signature = AddonKeys::b64u(sodium_crypto_sign_detached(self::PREFIX . '.' . $segment, $privateKey));

        return self::PREFIX . '.' . $segment . '.' . $signature;
    }

    /** @return array<string, array<string, mixed>> */
    private static function normalizeAddons(array $addons): array
    {
        $out = [];
        foreach ($addons as $entry) {
            if (is_string($entry)) {
                $id = trim($entry);
                if ($id !== '') {
                    $out[$id] = ['id' => $id];
                }
                continue;
            }
            if (is_array($entry)) {
                $id = trim((string) ($entry['id'] ?? ''));
                if ($id !== '') {
                    $out[$id] = $entry + ['id' => $id];
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function licenseId(): string
    {
        return (string) ($this->payload['lic'] ?? '');
    }

    public function instanceId(): string
    {
        return (string) ($this->payload['iid'] ?? '');
    }

    public function host(): string
    {
        return (string) ($this->payload['host'] ?? '');
    }

    /** @return array<string, array<string, mixed>> */
    public function addons(): array
    {
        return $this->payload['addons'];
    }

    /** @return list<string> */
    public function addonIds(): array
    {
        return array_keys($this->addons());
    }

    public function hasAddon(string $id): bool
    {
        return isset($this->addons()[$id]);
    }

    /** @return array<string, mixed>|null */
    public function addon(string $id): ?array
    {
        return $this->addons()[$id] ?? null;
    }

    public function isTrial(): bool
    {
        return !empty($this->payload['trial']);
    }

    public function issuedAt(): ?int
    {
        $value = trim((string) ($this->payload['iat'] ?? ''));

        return $value !== '' ? (int) strtotime($value) : null;
    }

    public function expiresAt(): ?int
    {
        $value = trim((string) ($this->payload['exp'] ?? ''));

        return $value !== '' ? (int) strtotime($value) : null;
    }

    public function isExpired(?int $now = null): bool
    {
        $expires = $this->expiresAt();
        if ($expires === null) {
            return false;
        }

        return ($now ?? time()) > $expires;
    }

    public function matchesInstance(string $instanceCode): bool
    {
        $bound = trim($this->instanceId());

        return $bound === '' || hash_equals($bound, $instanceCode);
    }
}
