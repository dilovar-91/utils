<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\TelegramService;
use App\Services\WhoisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckDomainWhoisJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public function __construct(public int $domainId)
    {
    }

    public function handle(
        WhoisService $whoisService,
        TelegramService $telegramService
    ): void {
        $domain = Domain::find($this->domainId);

        if (! $domain) {
            return;
        }

        $result = $whoisService->lookup($domain->domain);

        $domain->last_checked_at = now();
        $domain->raw_whois = $result['raw_whois'] ?? null;
        $domain->registrar = $result['registrar'] ?? null;

        if (! ($result['success'] ?? false)) {
            $domain->status = 'error';
            $domain->last_error = $result['error'] ?? 'Unknown error';
            $domain->save();

            return;
        }

        $domain->expires_at = $result['expires_at'];
        $domain->last_error = null;

        if (! $domain->expires_at) {
            $domain->status = 'unknown';
            $domain->save();
            return;
        }

        $daysLeft = now()->diffInDays($domain->expires_at, false);

        if ($daysLeft < 0) {
            $domain->status = 'expired';
        } elseif ($daysLeft < 30) {
            $domain->status = 'expiring';
        } else {
            $domain->status = 'active';
        }

        $domain->save();

        $this->notifyIfNeeded($domain, $daysLeft, $telegramService);
    }

    protected function notifyIfNeeded(
        Domain $domain,
        int $daysLeft,
        TelegramService $telegramService
    ): void {
        if ($daysLeft >= 30) {
            return;
        }

        // если уже истек
        if ($daysLeft < 0) {
            $alreadySent = $domain->last_expiry_notified_days === -1
                && $domain->last_expiry_notified_at?->isToday();

            if ($alreadySent) {
                return;
            }

            $message = implode("\n", [
                '🚨 <b>Domain expired</b>',
                '',
                'Domain: <b>' . e($domain->domain) . '</b>',
                'Expired at: <b>' . optional($domain->expires_at)->format('Y-m-d H:i') . '</b>',
                'Registrar: <b>' . e($domain->registrar ?? '-') . '</b>',
            ]);

            if ($telegramService->sendMessage($message)) {
                $domain->update([
                    'last_expiry_notified_at' => now(),
                    'last_expiry_notified_days' => -1,
                ]);
            }

            return;
        }

        // если меньше 8 дней: отправляем 1 раз в день
        $alreadySentToday = $domain->last_expiry_notified_at?->isToday()
            && $domain->last_expiry_notified_days === $daysLeft;

        if ($alreadySentToday) {
            return;
        }

        $message = implode("\n", [
            '⚠️ <b>Срок действия домена скоро истекает</b>',
            '',
            'Домен: <b>' . e($domain->domain) . '</b>',
            'Осталось дней: <b>' . $daysLeft . '</b>',
            'Истекает: <b>' . optional($domain->expires_at)->format('Y-m-d H:i') . '</b>',
            'Регистратор: <b>' . e($domain->registrar ?? '-') . '</b>',
        ]);
        if ($telegramService->sendMessage($message)) {
            $domain->update([
                'last_expiry_notified_at' => now(),
                'last_expiry_notified_days' => $daysLeft,
            ]);
        }
    }
}
