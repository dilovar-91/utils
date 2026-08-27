<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class SiteHealthService
{
    public function check(string $url): array
    {
        $url = $this->normalizeUrl($url);
        $startedAt = microtime(true);

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->withUserAgent('DAS-Utils HealthCheck/1.0')
                ->withOptions([
                    'http_errors' => false,
                    'allow_redirects' => true,
                ])
                ->get($url);

            $elapsedMs = $this->elapsedMs($startedAt);
            $statusCode = $response->status();
            $up = $statusCode >= 200 && $statusCode < 400;

            return [
                'up' => $up,
                'status_code' => $statusCode,
                'response_time_ms' => $elapsedMs,
                'error' => $up ? null : "HTTP {$statusCode}",
                'url' => $url,
            ];
        } catch (ConnectionException $exception) {
            return $this->failure($url, $startedAt, $exception->getMessage() ?: 'Connection failed');
        } catch (Throwable $exception) {
            return $this->failure($url, $startedAt, $exception->getMessage() ?: 'Unknown error');
        }
    }

    protected function failure(string $url, float $startedAt, string $error): array
    {
        return [
            'up' => false,
            'status_code' => null,
            'response_time_ms' => $this->elapsedMs($startedAt),
            'error' => $error,
            'url' => $url,
        ];
    }

    protected function elapsedMs(float $startedAt): int
    {
        return (int) max(0, round((microtime(true) - $startedAt) * 1000));
    }

    protected function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        return $url;
    }
}
