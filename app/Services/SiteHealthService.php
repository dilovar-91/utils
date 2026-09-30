<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class SiteHealthService
{
    public const CURL_RETRY_LIMIT = 3;

    public const RETRY_WINDOW_SECONDS = 90;

    public const ERROR_BODY_LIMIT = 1000;

    public const TELEGRAM_BODY_LIMIT = 180;

    public function __construct(protected PublicDnsLookup $dnsLookup) {}

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
        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            $dns = $this->dnsLookup->domainExists($host);

            if ($dns === false) {
                return [
                    'up' => false,
                    'retryable' => true,
                    'status_code' => null,
                    'response_time_ms' => $this->elapsedMs($startedAt),
                    'error' => 'Could not resolve host',
                    'url' => $url,
                ];
            }
        }

        try {
            $response = Http::timeout(20)
                ->connectTimeout(15)
                ->withUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36')
                ->withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                ])
                ->withRequestMiddleware(function ($request) {
                    return $request->withoutHeader('Content-Type');
                })
                ->withOptions([
                    'http_errors' => false,
                    'allow_redirects' => true,
                    'verify' => false,
                    'force_ip_resolve' => 'v4',
                    'version' => 1.1,
                    'curl' => [
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        CURLOPT_ENCODING => '',
                    ],
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
                'error' => $this->formatHttpError($statusCode, $response->body()),
                'url' => $url,
            ];
        } catch (Throwable $exception) {
            return $this->classifyException($url, $startedAt, $exception);
        }
    }

    /**
     * @return array{
     *     up: bool,
     *     retryable: bool,
     *     status_code: int|null,
     *     response_time_ms: int,
     *     error: string|null,
     *     url: string,
     *     attempt: int
     * }
     */
    public function checkWithRetries(string $url): array
    {
        $windowStartedAt = microtime(true);
        $attempt = 0;
        $result = null;

        do {
            $attempt++;
            $result = $this->check($url);

            if ($result['up'] || ! $result['retryable']) {
                break;
            }
        } while (
            $attempt < self::CURL_RETRY_LIMIT
            && (microtime(true) - $windowStartedAt) < self::RETRY_WINDOW_SECONDS
        );

        $result['attempt'] = $attempt;

        return $result;
    }

    public function telegramErrorSnippet(?string $error): string
    {
        $error = trim((string) $error);

        if ($error === '') {
            return '-';
        }

        return $this->truncate($error, self::TELEGRAM_BODY_LIMIT);
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
            'retryable' => in_array($kind, ['retryable', 'dns'], true),
            'status_code' => null,
            'response_time_ms' => $this->elapsedMs($startedAt),
            'error' => match ($kind) {
                'ssl' => null,
                'dns' => 'Could not resolve host',
                default => $error,
            },
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

    protected function formatHttpError(int $statusCode, string $body): string
    {
        $snippet = $this->extractResponseText($body, self::ERROR_BODY_LIMIT);

        if ($snippet === null) {
            return "HTTP {$statusCode}";
        }

        return "HTTP {$statusCode}: {$snippet}";
    }

    protected function extractResponseText(string $body, int $limit): ?string
    {
        $body = trim($body);

        if ($body === '') {
            return null;
        }

        $body = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $body) ?? $body;
        $body = preg_replace('/<[^>]+>/u', ' ', $body) ?? $body;
        $text = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        return $this->truncate($text, $limit);
    }

    protected function truncate(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $limit - 1)).'…';
    }

    protected function curlErrorKind(Throwable $exception, string $error): string
    {
        if (preg_match('/cURL error 6\b/i', $error) || str_contains($error, 'Could not resolve host')) {
            return 'dns';
        }

        if (preg_match('/cURL error (51|60|83)\b/i', $error) || str_contains(strtolower($error), 'ssl certificate')) {
            return 'ssl';
        }

        if (
            $exception instanceof ConnectionException
            || preg_match('/cURL error (7|28|35)\b/i', $error)
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
