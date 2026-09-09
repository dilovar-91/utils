<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class SiteHealthService
{
    public const CURL_RETRY_LIMIT = 3;

    /**
     * @return array{
     *     up: bool,
     *     retryable: bool,
     *     status_code: int|null,
     *     response_time_ms: int,
     *     error: string|null,
     *     url: string
     * }
     */
    public function check(string $url): array
    {
        $url = $this->normalizeUrl($url);
        $startedAt = microtime(true);

        try {
            $response = Http::timeout(20)
                ->connectTimeout(15)
                ->withUserAgent('DAS-Utils HealthCheck/1.0')
                ->withOptions([
                    'http_errors' => false,
                    'allow_redirects' => true,
                    'verify' => false,
                    'force_ip_resolve' => 'v4',
                ])
                ->get($url);

            $elapsedMs = $this->elapsedMs($startedAt);
            $statusCode = $response->status();

            if ($this->isSuccessfulStatus($statusCode)) {
                return [
                    'up' => true,
                    'retryable' => false,
                    'status_code' => $statusCode,
                    'response_time_ms' => $elapsedMs,
                    'error' => null,
                    'url' => $url,
                ];
            }

            return [
                'up' => false,
                'retryable' => false,
                'status_code' => $statusCode,
                'response_time_ms' => $elapsedMs,
                'error' => "HTTP {$statusCode}",
                'url' => $url,
            ];
        } catch (Throwable $exception) {
            return $this->classifyException($url, $startedAt, $exception);
        }
    }

    public function formatCurlError(string $error, int $attempt, int $limit = self::CURL_RETRY_LIMIT): string
    {
        return $this->cleanError($error)." (попытка {$attempt}/{$limit})";
    }

    /**
     * @return array{
     *     up: bool,
     *     retryable: bool,
     *     status_code: int|null,
     *     response_time_ms: int,
     *     error: string|null,
     *     url: string
     * }
     */
    protected function classifyException(string $url, float $startedAt, Throwable $exception): array
    {
        $error = $this->cleanError($exception->getMessage() ?: 'Connection failed');
        $kind = $this->curlErrorKind($exception, $error);

        return [
            'up' => $kind === 'ssl',
            'retryable' => $kind === 'retryable',
            'status_code' => null,
            'response_time_ms' => $this->elapsedMs($startedAt),
            'error' => $kind === 'ssl' ? null : $error,
            'url' => $url,
        ];
    }

    protected function isSuccessfulStatus(int $statusCode): bool
    {
        if ($statusCode >= 200 && $statusCode < 400) {
            return true;
        }

        return in_array($statusCode, [403, 404], true);
    }

    protected function curlErrorKind(Throwable $exception, string $error): string
    {
        if (preg_match('/cURL error (51|60|83)\b/i', $error) || str_contains(strtolower($error), 'ssl certificate')) {
            return 'ssl';
        }

        if (
            $exception instanceof ConnectionException
            || preg_match('/cURL error (6|7|28|35)\b/i', $error)
            || str_contains(strtolower($error), 'timed out')
            || str_contains(strtolower($error), 'timeout')
        ) {
            return 'retryable';
        }

        return 'retryable';
    }

    protected function cleanError(string $error): string
    {
        $error = preg_replace('/\s*\(see https:\/\/curl\.haxx\.se[^)]+\)/i', '', $error) ?? $error;
        $error = preg_replace('/\s+for https?:\/\/\S+/i', '', $error) ?? $error;

        return trim($error);
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
