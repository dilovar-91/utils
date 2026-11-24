<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\AlertNotifier;
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
        AlertNotifier $notifier
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

        $daysLeft = (int) now()->diffInDays($domain->expires_at, false);

        if ($daysLeft < 0) {
            $domain->status = 'expired';
        } elseif ($daysLeft < 30) {
            $domain->status = 'expiring';
        } else {
            $domain->status = 'active';
        }

        $domain->save();

        $this->notifyIfNeeded($domain, $daysLeft, $notifier);
    }

    protected function notifyIfNeeded(
        Domain $domain,
        int $daysLeft,
        AlertNotifier $notifier
    ): void {
        if ($daysLeft >= 30) {
            return;
        }

        $marker = $daysLeft < 0 ? -1 : $daysLeft;

        $telegramDue = ! (
            $domain->last_expiry_notified_days === $marker
            && $domain->last_expiry_notified_at?->isToday()
        );

        $mailDue = ! (
            $domain->last_expiry_mailed_days === $marker
            && $domain->last_expiry_mailed_at?->isToday()
        );

        if (! $telegramDue && ! $mailDue) {
            return;
        }

        $message = $daysLeft < 0
            ? implode("\n", [
                '🚨 <b>Домен истёк</b>',
                '',
                'Домен: <b>'.e($domain->domain).'</b>',
                'Дата истечения: <b>'.optional($domain->expires_at)->format('Y-m-d H:i').'</b>',
                'Регистратор: <b>'.e($domain->registrar ?? '-').'</b>',
            ])
            : implode("\n", [
                '⚠️ <b>Срок действия домена скоро истекает</b>',
                '',
                'Домен: <b>'.e($domain->domain).'</b>',
                'Осталось дней: <b>'.$daysLeft.'</b>',
                'Истекает: <b>'.optional($domain->expires_at)->format('Y-m-d H:i').'</b>',
                'Регистратор: <b>'.e($domain->registrar ?? '-').'</b>',
            ]);

        $updates = [];

        if ($telegramDue && $notifier->sendTelegram($message)) {
            $updates['last_expiry_notified_at'] = now();
            $updates['last_expiry_notified_days'] = $marker;
        }

        if ($mailDue && $notifier->sendMail($message)) {
            $updates['last_expiry_mailed_at'] = now();
            $updates['last_expiry_mailed_days'] = $marker;
        }

        if ($updates !== []) {
            $domain->update($updates);
        }
    }
}
