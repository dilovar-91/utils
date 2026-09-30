<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\SiteHealthService;
use App\Services\TelegramService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckSiteHealthJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $domainId) {}

    public function handle(
        SiteHealthService $healthService,
        TelegramService $telegramService
    ): void {
        $domain = Domain::find($this->domainId);

        if (! $domain) {
            return;
        }

        $previousStatus = $domain->health_status ?? 'unknown';
        $result = $healthService->checkWithRetries($domain->getHealthCheckUrl());

        if ($result['up']) {
            $this->markChecked($domain, $result, 'up');
            $this->notifyIfNeeded($domain, $previousStatus, $telegramService, $healthService);

            return;
        }

        if (! $result['retryable']) {
            $this->markChecked($domain, $result, 'down');
            $this->notifyIfNeeded($domain, $previousStatus, $telegramService, $healthService);

            return;
        }

        $limit = SiteHealthService::CURL_RETRY_LIMIT;
        $attempt = min($result['attempt'] ?? $limit, $limit);

        $domain->health_status = 'down';
        $domain->health_status_code = null;
        $domain->health_response_time_ms = $result['response_time_ms'];
        $domain->last_health_checked_at = now();
        $domain->last_health_error = $healthService->formatCurlError((string) $result['error'], $attempt, $limit);
        $domain->health_curl_fail_count = $attempt;
        $domain->save();

        $this->notifyIfNeeded($domain, $previousStatus, $telegramService, $healthService);
    }

    /**
     * @param  array{up: bool, status_code: int|null, response_time_ms: int, error: string|null}  $result
     */
    protected function markChecked(Domain $domain, array $result, string $status): void
    {
        $domain->health_status = $status;
        $domain->health_status_code = $result['status_code'];
        $domain->health_response_time_ms = $result['response_time_ms'];
        $domain->last_health_checked_at = now();
        $domain->last_health_error = $result['error'];
        $domain->health_curl_fail_count = 0;
        $domain->save();
    }

    protected function notifyIfNeeded(
        Domain $domain,
        string $previousStatus,
        TelegramService $telegramService,
        SiteHealthService $healthService
    ): void {
        $status = $domain->health_status;

        if ($status === $previousStatus) {
            return;
        }

        if ($status === 'up' && $previousStatus !== 'down') {
            return;
        }

        if ($status === 'down') {
            $message = implode("\n", [
                '🔴 <b>Сайт недоступен</b>',
                e($domain->domain),
                'Код: <b>'.e((string) ($domain->health_status_code ?? '-')).'</b>',
                e($healthService->telegramErrorSnippet($domain->last_health_error)),
            ]);
        } else {
            $message = implode("\n", [
                '🟢 <b>Сайт снова доступен</b>',
                e($domain->domain),
                'Код: <b>'.e((string) ($domain->health_status_code ?? '-')).'</b>',
            ]);
        }

        if ($telegramService->sendMessage($message)) {
            $domain->update([
                'last_health_notified_at' => now(),
                'last_health_notified_status' => $status,
            ]);
        }
    }
}
