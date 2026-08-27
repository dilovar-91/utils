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
        $result = $healthService->check($domain->getHealthCheckUrl());

        $domain->health_status = $result['up'] ? 'up' : 'down';
        $domain->health_status_code = $result['status_code'];
        $domain->health_response_time_ms = $result['response_time_ms'];
        $domain->last_health_checked_at = now();
        $domain->last_health_error = $result['error'];
        $domain->save();

        $this->notifyIfNeeded($domain, $previousStatus, $telegramService);
    }

    protected function notifyIfNeeded(
        Domain $domain,
        string $previousStatus,
        TelegramService $telegramService
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
                '',
                'Сайт: <b>'.e($domain->domain).'</b>',
                'URL: '.e($domain->getHealthCheckUrl()),
                'Код ответа: <b>'.e((string) ($domain->health_status_code ?? '-')).'</b>',
                'Ошибка: <b>'.e($domain->last_health_error ?? '-').'</b>',
            ]);
        } else {
            $message = implode("\n", [
                '🟢 <b>Сайт снова доступен</b>',
                '',
                'Сайт: <b>'.e($domain->domain).'</b>',
                'URL: '.e($domain->getHealthCheckUrl()),
                'Код ответа: <b>'.e((string) ($domain->health_status_code ?? '-')).'</b>',
                'Время ответа: <b>'.e((string) ($domain->health_response_time_ms ?? '-')).' мс</b>',
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
