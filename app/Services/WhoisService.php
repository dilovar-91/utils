<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class WhoisService
{
    public function lookup(string $domain): array
    {
        $domain = $this->normalizeDomain($domain);

        $whois = $this->lookupWhois($domain);

        if (($whois['success'] ?? false) && ($whois['expires_at'] ?? null)) {
            return $whois;
        }

        $rdap = $this->lookupRdap($domain);

        if ($rdap['success'] ?? false) {
            return $rdap;
        }

        if (($whois['raw_whois'] ?? '') !== '') {
            return $whois;
        }

        return $rdap;
    }

    protected function lookupWhois(string $domain): array
    {
        $process = new Process(['whois', $domain]);
        $process->setTimeout(30);
        $process->run();

        $output = trim($process->getOutput() . "\n" . $process->getErrorOutput());

        if (! $process->isSuccessful() || $output === '') {
            return [
                'success' => false,
                'domain' => $domain,
                'expires_at' => null,
                'registrar' => null,
                'raw_whois' => $output,
                'error' => 'WHOIS command failed or returned empty output',
            ];
        }

        $expiresAt = $this->extractExpiryDate($output);
        $registrar = $this->extractRegistrar($output);

        return [
            'success' => true,
            'domain' => $domain,
            'expires_at' => $expiresAt,
            'registrar' => $registrar,
            'raw_whois' => $output,
            'error' => null,
        ];
    }

    protected function lookupRdap(string $domain): array
    {
        $ascii = $this->toAscii($domain);
        $urls = [];

        $baseUrl = $this->rdapBaseUrl($ascii);
        if ($baseUrl) {
            $urls[] = $baseUrl . 'domain/' . rawurlencode($ascii);
        }

        $urls[] = 'https://rdap.org/domain/' . rawurlencode($ascii);

        $lastError = 'RDAP lookup failed';

        foreach (array_unique($urls) as $url) {
            try {
                $response = Http::timeout(20)
                    ->accept('application/rdap+json, application/json')
                    ->get($url);

                if (! $response->successful()) {
                    $lastError = 'RDAP request failed with HTTP ' . $response->status();
                    continue;
                }

                $data = $response->json();

                if (! is_array($data) || ($data['objectClassName'] ?? null) !== 'domain') {
                    $lastError = 'RDAP returned an unexpected payload';
                    continue;
                }

                return [
                    'success' => true,
                    'domain' => $domain,
                    'expires_at' => $this->extractRdapExpiry($data),
                    'registrar' => $this->extractRdapRegistrar($data),
                    'raw_whois' => $this->formatRdapAsWhois($data, $url),
                    'error' => null,
                ];
            } catch (Throwable $e) {
                $lastError = 'RDAP request failed: ' . $e->getMessage();
            }
        }

        return [
            'success' => false,
            'domain' => $domain,
            'expires_at' => null,
            'registrar' => null,
            'raw_whois' => null,
            'error' => $lastError,
        ];
    }

    protected function rdapBaseUrl(string $domain): ?string
    {
        $map = $this->rdapBootstrapMap();
        $labels = explode('.', $domain);

        for ($i = 1; $i < count($labels); $i++) {
            $suffix = implode('.', array_slice($labels, $i));

            if (isset($map[$suffix])) {
                return $map[$suffix];
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    protected function rdapBootstrapMap(): array
    {
        try {
            return Cache::remember('rdap_dns_bootstrap_map', now()->addDay(), fn () => $this->fetchRdapBootstrapMap());
        } catch (Throwable) {
            return $this->fetchRdapBootstrapMap();
        }
    }

    /**
     * @return array<string, string>
     */
    protected function fetchRdapBootstrapMap(): array
    {
        $response = Http::timeout(20)->get('https://data.iana.org/rdap/dns.json');

        if (! $response->successful()) {
            return [];
        }

        $map = [];

        foreach ($response->json('services') ?? [] as $service) {
            $url = rtrim((string) ($service[1][0] ?? ''), '/') . '/';

            if ($url === '/') {
                continue;
            }

            foreach ($service[0] ?? [] as $tld) {
                $map[strtolower((string) $tld)] = $url;
            }
        }

        return $map;
    }

    protected function extractRdapExpiry(array $data): ?Carbon
    {
        foreach ($data['events'] ?? [] as $event) {
            if (($event['eventAction'] ?? '') !== 'expiration' || empty($event['eventDate'])) {
                continue;
            }

            try {
                return Carbon::parse($event['eventDate']);
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    protected function extractRdapRegistrar(array $data): ?string
    {
        foreach ($data['entities'] ?? [] as $entity) {
            if (! in_array('registrar', $entity['roles'] ?? [], true)) {
                continue;
            }

            $name = $this->vcardFullName($entity);

            if ($name) {
                return Str::of($name)->limit(255, '')->toString();
            }
        }

        return null;
    }

    protected function vcardFullName(array $entity): ?string
    {
        foreach ($entity['vcardArray'][1] ?? [] as $property) {
            if (($property[0] ?? null) === 'fn' && filled($property[3] ?? null)) {
                return trim((string) $property[3]);
            }
        }

        return null;
    }

    protected function formatRdapAsWhois(array $data, string $sourceUrl): string
    {
        $lines = [
            'Domain Name: ' . ($data['ldhName'] ?? $data['unicodeName'] ?? ''),
        ];

        foreach ($data['events'] ?? [] as $event) {
            $date = $event['eventDate'] ?? null;
            $action = $event['eventAction'] ?? '';

            if (! $date) {
                continue;
            }

            $label = match ($action) {
                'expiration' => 'Registry Expiry Date',
                'registration' => 'Creation Date',
                'last changed' => 'Updated Date',
                default => null,
            };

            if ($label) {
                $lines[] = $label . ': ' . $date;
            }
        }

        $registrar = $this->extractRdapRegistrar($data);
        if ($registrar) {
            $lines[] = 'Registrar: ' . $registrar;
        }

        foreach ($data['status'] ?? [] as $status) {
            $lines[] = 'Domain Status: ' . $status;
        }

        foreach ($data['nameservers'] ?? [] as $nameserver) {
            if (! empty($nameserver['ldhName'])) {
                $lines[] = 'Name Server: ' . $nameserver['ldhName'];
            }
        }

        $lines[] = 'Source: RDAP (' . $sourceUrl . ')';

        return implode("\n", $lines);
    }

    protected function extractExpiryDate(string $text): ?Carbon
    {
        $patterns = [
            '/Registry Expiry Date:\s*(.+)/i',
            '/Registrar Registration Expiration Date:\s*(.+)/i',
            '/Expiration Date:\s*(.+)/i',
            '/Expiry Date:\s*(.+)/i',
            '/expires:\s*(.+)/i',
            '/paid-till:\s*(.+)/i',
            '/renewal date:\s*(.+)/i',
            '/expire:\s*(.+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $value = trim($matches[1]);
                $value = preg_replace('/\s+UTC$/i', '', $value);
                $value = preg_replace('/\s+\(.*\)$/', '', $value);

                try {
                    return Carbon::parse($value);
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    protected function extractRegistrar(string $text): ?string
    {
        $patterns = [
            '/Registrar:\s*(.+)/i',
            '/registrar:\s*(.+)/i',
            '/Sponsoring Registrar:\s*(.+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return Str::of(trim($matches[1]))->limit(255, '')->toString();
            }
        }

        return null;
    }

    protected function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = explode('/', $domain)[0];
        $domain = explode('?', $domain)[0];

        return rtrim($domain, '.');
    }

    protected function toAscii(string $domain): string
    {
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (is_string($ascii) && $ascii !== '') {
                return strtolower($ascii);
            }
        }

        return $domain;
    }
}
