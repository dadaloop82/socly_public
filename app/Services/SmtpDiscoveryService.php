<?php

declare(strict_types=1);

namespace Socly\Services;

/**
 * Generic outbound SMTP autodiscovery from the sender email domain.
 *
 * Sources (in priority order):
 *  1. Mozilla/Thunderbird autoconfig XML
 *  2. DNS SRV (_submission._tcp, _smtps._tcp, _smtp._tcp)
 *  3. MX records and hosts derived from MX / provider domain
 *  4. Common hostname patterns on the email domain (smtp., mail., …)
 *
 * Each host is TCP-probed on standard submission ports before attempting AUTH.
 */
final class SmtpDiscoveryService
{
    private const MAX_AUTH_ATTEMPTS = 18;
    private const MAX_TCP_PROBES = 28;
    private const TCP_TIMEOUT = 2;
    private const AUTH_TIMEOUT = 6;
    private const WALL_CLOCK_SECONDS = 32;

    /** @var list<array{port:int,encryption:string,weight:int}> */
    private const STANDARD_PORTS = [
        ['port' => 587, 'encryption' => 'tls', 'weight' => 100],
        ['port' => 465, 'encryption' => 'ssl', 'weight' => 90],
        ['port' => 2525, 'encryption' => 'tls', 'weight' => 70],
    ];

    public function __construct(
        private readonly MailService $mail
    ) {
    }

    /**
     * @return array{
     *   ok:bool,
     *   config?:array{host:string,port:int,encryption:string,username:string,password:string,from_address:string},
     *   suggestion?:array{host:string,port:int,encryption:string,username:string},
     *   tried?:int,
     *   last_error?:string,
     *   unreachable?:bool
     * }
     */
    public function discover(string $fromAddress, string $password, string $username = ''): array
    {
        $fromAddress = trim($fromAddress);
        $password = (string) $password;
        $username = trim($username !== '' ? $username : $fromAddress);
        if ($fromAddress === '' || $password === '' || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'tried' => 0];
        }

        $domain = strtolower((string) substr(strrchr($fromAddress, '@') ?: '', 1));
        if ($domain === '') {
            return ['ok' => false, 'tried' => 0];
        }

        $usernames = $this->usernameVariants($fromAddress, $username);
        $candidates = $this->candidateConfigs($domain);
        $suggestion = $this->bestSuggestion($domain, $fromAddress, $username, $candidates);
        $deadline = microtime(true) + self::WALL_CLOCK_SECONDS;

        $tcpTried = 0;
        $authTried = 0;
        $lastError = '';
        $anyReachable = false;
        $timeoutCount = 0;
        /** @var array<string, bool> $openPorts */
        $openPorts = [];

        foreach ($candidates as $candidate) {
            if (microtime(true) >= $deadline || $tcpTried >= self::MAX_TCP_PROBES) {
                break;
            }

            $host = $candidate['host'];
            $port = $candidate['port'];
            $portKey = strtolower($host) . ':' . $port;

            if (!array_key_exists($portKey, $openPorts)) {
                $tcpTried++;
                $open = $this->tcpReachable($host, $port, self::TCP_TIMEOUT);
                $openPorts[$portKey] = $open;
                if (!$open) {
                    $timeoutCount++;
                    $lastError = 'Connection timed out to ' . $host . ':' . $port;
                    continue;
                }
            } elseif (!$openPorts[$portKey]) {
                continue;
            }

            $anyReachable = true;

            foreach ($usernames as $user) {
                if (microtime(true) >= $deadline || $authTried >= self::MAX_AUTH_ATTEMPTS) {
                    break 2;
                }
                $authTried++;

                $cfg = [
                    'host' => $host,
                    'port' => $port,
                    'encryption' => $candidate['encryption'],
                    'username' => $user,
                    'password' => $password,
                    'from_address' => $fromAddress,
                    'from_name' => '',
                ];

                $test = $this->mail->testConnection($cfg, self::AUTH_TIMEOUT);
                if (!empty($test['ok'])) {
                    return [
                        'ok' => true,
                        'config' => $cfg,
                        'tried' => $tcpTried + $authTried,
                    ];
                }
                $lastError = (string) ($test['error'] ?? $lastError);
            }
        }

        return [
            'ok' => false,
            'suggestion' => $suggestion,
            'tried' => $tcpTried + $authTried,
            'last_error' => $lastError,
            'unreachable' => !$anyReachable && $timeoutCount > 0,
        ];
    }

    /**
     * @return array{host:string,port:int,encryption:string,username:string,password:string,from_address:string}|null
     */
    public function findWorkingConfig(string $fromAddress, string $password, string $username = ''): ?array
    {
        $result = $this->discover($fromAddress, $password, $username);
        return !empty($result['ok']) && isset($result['config']) ? $result['config'] : null;
    }

    /** @return list<string> */
    private function usernameVariants(string $fromAddress, string $username): array
    {
        $local = (string) strstr($fromAddress, '@', true);
        $out = [];
        foreach ([$username, $fromAddress, $local] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }
            $key = strtolower($candidate);
            if (!isset($out[$key])) {
                $out[$key] = $candidate;
            }
        }

        return array_values($out);
    }

    /**
     * @param list<array{host:string,port:int,encryption:string,priority:int}> $candidates
     * @return array{host:string,port:int,encryption:string,username:string}
     */
    private function bestSuggestion(string $domain, string $fromAddress, string $username, array $candidates): array
    {
        $user = $username !== '' ? $username : $fromAddress;
        $seenHosts = [];

        // Prefer submission ports (587/465/2525) — port 25 is inbound relay, not for clients.
        foreach ($candidates as $candidate) {
            if ($candidate['port'] === 25) {
                continue;
            }
            $host = $candidate['host'];
            if (isset($seenHosts[$host])) {
                continue;
            }
            $seenHosts[$host] = true;
            if ($this->tcpReachable($host, $candidate['port'], self::TCP_TIMEOUT)) {
                return [
                    'host' => $host,
                    'port' => $candidate['port'],
                    'encryption' => $candidate['encryption'],
                    'username' => $user,
                ];
            }
        }

        $fallbackHost = 'smtp.' . $domain;
        foreach ($candidates as $candidate) {
            if ($candidate['port'] === 25) {
                continue;
            }
            if ($this->hostResolves($candidate['host'])) {
                $fallbackHost = $candidate['host'];
                return [
                    'host' => $fallbackHost,
                    'port' => $candidate['port'],
                    'encryption' => $candidate['encryption'],
                    'username' => $user,
                ];
            }
        }

        $mx = $this->mxHosts($domain);
        if ($mx !== []) {
            foreach ($this->hostsFromMxHost($mx[0], $domain) as $host) {
                if ($this->hostResolves($host)) {
                    $fallbackHost = $host;
                    break;
                }
            }
        }

        return [
            'host' => $fallbackHost,
            'port' => 587,
            'encryption' => 'tls',
            'username' => $user,
        ];
    }

    /**
     * @return list<array{host:string,port:int,encryption:string,priority:int}>
     */
    private function candidateConfigs(string $domain): array
    {
        /** @var array<string, array{host:string,port:int,encryption:string,priority:int}> $ranked */
        $ranked = [];

        $add = function (string $host, int $port, string $encryption, int $priority) use (&$ranked): void {
            $host = strtolower(rtrim(trim($host), '.'));
            if ($host === '' || $port < 1 || $port > 65535) {
                return;
            }
            if (!$this->hostResolves($host)) {
                return;
            }
            $key = $host . ':' . $port . ':' . $encryption;
            if (!isset($ranked[$key]) || $ranked[$key]['priority'] < $priority) {
                $ranked[$key] = [
                    'host' => $host,
                    'port' => $port,
                    'encryption' => $encryption,
                    'priority' => $priority,
                ];
            }
        };

        $addPorts = function (string $host, int $basePriority) use ($add): void {
            foreach (self::STANDARD_PORTS as $spec) {
                $add($host, $spec['port'], $spec['encryption'], $basePriority + (int) ($spec['weight'] / 10));
            }
        };

        foreach ($this->fromAutoconfig($domain) as $row) {
            $add($row['host'], $row['port'], $row['encryption'], 100);
        }

        foreach ($this->fromSrvRecords($domain) as $row) {
            $add($row['host'], $row['port'], $row['encryption'], 95);
        }

        foreach ($this->mxHosts($domain) as $mxHost) {
            foreach ($this->hostsFromMxHost($mxHost, $domain) as $host) {
                $addPorts($host, 80);
            }
        }

        foreach ($this->fromDomainPatterns($domain) as $host) {
            $addPorts($host, 50);
        }

        $out = array_values($ranked);
        usort($out, static function (array $a, array $b): int {
            return $b['priority'] <=> $a['priority'];
        });

        return $out;
    }

    /**
     * Derive plausible SMTP hostnames from an MX target (generic, no provider list).
     *
     * @return list<string>
     */
    private function hostsFromMxHost(string $mxHost, string $emailDomain): array
    {
        $mxHost = strtolower(rtrim($mxHost, '.'));
        $emailDomain = strtolower(rtrim($emailDomain, '.'));
        $candidates = [$mxHost];

        // Sibling service names on the same suffix (mail. ↔ smtp. ↔ mx. …).
        if (preg_match('/^(mail|smtp|mx|outbound|relay|smtp-out|postfix)\.(.+)$/i', $mxHost, $m)) {
            $suffix = $m[2];
            foreach (['smtp', 'mail', 'mx', 'outbound', 'relay'] as $prefix) {
                $candidates[] = $prefix . '.' . $suffix;
            }
        }

        // Provider base domain from MX (e.g. mail.register.it → register.it).
        $mxBase = $this->registrableDomain($mxHost);
        if ($mxBase !== '' && $mxBase !== $mxHost) {
            foreach (['smtp', 'mail', 'mx', 'outbound', 'relay', 'authsmtp'] as $prefix) {
                $candidates[] = $prefix . '.' . $mxBase;
            }
        }

        // Shared securemail / webnode style endpoints often used by Italian registrars.
        $blob = $mxHost . ' ' . $emailDomain;
        if (str_contains($blob, 'register.it') || str_contains($blob, 'securemail') || str_contains($blob, 'webnode')) {
            $candidates[] = 'authsmtp.securemail.pro';
            $candidates[] = 'smtp.securemail.pro';
        }

        // When MX lives on the same domain as the mailbox, also try local patterns.
        if ($mxBase === $emailDomain || str_ends_with($mxHost, '.' . $emailDomain)) {
            foreach (['smtp', 'mail', 'mx', 'outbound'] as $prefix) {
                $candidates[] = $prefix . '.' . $emailDomain;
            }
        }

        $out = [];
        foreach ($candidates as $host) {
            $host = strtolower(rtrim($host, '.'));
            if ($host === '' || isset($out[$host])) {
                continue;
            }
            $out[$host] = $host;
        }

        return array_values($out);
    }

    /** @return list<string> */
    private function fromDomainPatterns(string $domain): array
    {
        $domain = strtolower(rtrim($domain, '.'));
        $hosts = [
            'smtp.' . $domain,
            'mail.' . $domain,
            'mx.' . $domain,
            'outbound.' . $domain,
            'relay.' . $domain,
        ];

        $mx = $this->mxHosts($domain);
        if ($mx === [] || in_array($domain, $mx, true)) {
            $hosts[] = $domain;
        }

        $out = [];
        foreach ($hosts as $host) {
            if ($this->hostResolves($host)) {
                $out[] = $host;
            }
        }

        return $out;
    }

    /** @return list<array{host:string,port:int,encryption:string}> */
    private function fromAutoconfig(string $domain): array
    {
        $urls = [
            'https://autoconfig.thunderbird.net/v1.1/' . rawurlencode($domain),
            'https://' . $domain . '/.well-known/autoconfig/mail/config-v1.1.xml',
            'https://autoconfig.' . $domain . '/mail/config-v1.1.xml',
            'http://autoconfig.' . $domain . '/mail/config-v1.1.xml',
        ];

        $out = [];
        foreach ($urls as $url) {
            $xml = $this->fetchXml($url);
            if ($xml === null) {
                continue;
            }
            $servers = $xml->xpath('//outgoingServer[@type="smtp"]') ?: [];
            foreach ($servers as $server) {
                $host = trim((string) ($server->hostname ?? ''));
                if ($host === '') {
                    continue;
                }
                $port = (int) ($server->port ?? 587);
                if ($port < 1 || $port > 65535) {
                    $port = 587;
                }
                $out[] = [
                    'host' => strtolower(rtrim($host, '.')),
                    'port' => $port,
                    'encryption' => $this->mapSocketType((string) ($server->socketType ?? 'STARTTLS')),
                ];
            }
            if ($out !== []) {
                break;
            }
        }

        return $out;
    }

    /** @return list<array{host:string,port:int,encryption:string}> */
    private function fromSrvRecords(string $domain): array
    {
        $out = [];
        $patterns = [
            ['name' => '_submission._tcp.' . $domain, 'encryption' => 'tls'],
            ['name' => '_smtps._tcp.' . $domain, 'encryption' => 'ssl'],
            ['name' => '_smtp._tcp.' . $domain, 'encryption' => 'tls'],
        ];
        foreach ($patterns as $pattern) {
            $records = @dns_get_record($pattern['name'], DNS_SRV);
            if (!is_array($records)) {
                continue;
            }
            usort($records, static function (array $a, array $b): int {
                $pri = ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0);
                if ($pri !== 0) {
                    return $pri;
                }
                return ($b['weight'] ?? 0) <=> ($a['weight'] ?? 0);
            });
            foreach ($records as $record) {
                $host = rtrim(strtolower((string) ($record['target'] ?? '')), '.');
                $port = (int) ($record['port'] ?? 0);
                if ($host === '' || $port < 1) {
                    continue;
                }
                $out[] = [
                    'host' => $host,
                    'port' => $port,
                    'encryption' => $pattern['encryption'],
                ];
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function mxHosts(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_MX);
        if (!is_array($records) || $records === []) {
            return [];
        }
        usort($records, static fn (array $a, array $b): int => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));
        $out = [];
        foreach ($records as $record) {
            $host = strtolower(rtrim((string) ($record['target'] ?? ''), '.'));
            if ($host === '' || isset($out[$host])) {
                continue;
            }
            $out[$host] = $host;
        }

        return array_values($out);
    }

    /**
     * Best-effort registrable domain (works for .it, .com, .co.uk-style via last two labels).
     */
    private function registrableDomain(string $host): string
    {
        $host = strtolower(rtrim($host, '.'));
        $parts = explode('.', $host);
        if (count($parts) < 2) {
            return $host;
        }

        $twoPartTlds = ['co.uk', 'com.au', 'co.it', 'gov.it'];
        $lastTwo = implode('.', array_slice($parts, -2));
        $lastThree = count($parts) >= 3 ? implode('.', array_slice($parts, -3)) : '';

        if (in_array($lastTwo, $twoPartTlds, true) && count($parts) >= 3) {
            return implode('.', array_slice($parts, -3));
        }
        if (in_array($lastThree, $twoPartTlds, true) && count($parts) >= 4) {
            return implode('.', array_slice($parts, -4));
        }

        return implode('.', array_slice($parts, -2));
    }

    private function tcpReachable(string $host, int $port, int $timeoutSeconds): bool
    {
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, max(1, $timeoutSeconds));
        if (!is_resource($fp)) {
            return false;
        }
        fclose($fp);

        return true;
    }

    private function hostResolves(string $host): bool
    {
        $host = rtrim(strtolower($host), '.');
        if ($host === '') {
            return false;
        }
        if (@dns_get_record($host, DNS_A) || @dns_get_record($host, DNS_AAAA)) {
            return true;
        }
        $ip = @gethostbyname($host);
        return is_string($ip) && $ip !== '' && $ip !== $host;
    }

    private function mapSocketType(string $socketType): string
    {
        $socketType = strtoupper(trim($socketType));
        return match ($socketType) {
            'SSL', 'SSL/TLS' => 'ssl',
            'PLAIN', 'NONE' => 'none',
            default => 'tls',
        };
    }

    private function fetchXml(string $url): ?\SimpleXMLElement
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 3,
                'user_agent' => 'SOCLY SMTP autodiscovery',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (!is_string($body) || trim($body) === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml instanceof \SimpleXMLElement ? $xml : null;
    }
}
