<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class WhoisService
{
    public function lookup(string $domain): array
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
                } catch (\Throwable) {
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
}
