<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramService
{
    public function sendMessage(string $message): bool
    {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (! $token || ! $chatId) {
            return false;
        }

        try {
            $request = Http::timeout((int) config('services.telegram.timeout', 20))
                ->connectTimeout((int) config('services.telegram.connect_timeout', 10))
                ->asForm()
                ->withOptions($this->httpOptions());

            $response = $request->post($this->sendMessageUrl($token), [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            if ($response->successful()) {
                return true;
            }

            Log::warning('Telegram notification rejected', [
                'status' => $response->status(),
            ]);

            return false;
        } catch (Throwable $exception) {
            Log::warning('Telegram notification failed', [
                'error' => str_replace((string) $token, '[redacted]', $exception->getMessage()),
            ]);

            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function httpOptions(): array
    {
        $options = [
            'force_ip_resolve' => 'v4',
        ];

        $proxy = config('services.telegram.proxy');

        if (filled($proxy)) {
            $options['proxy'] = $proxy;
        }

        return $options;
    }

    protected function sendMessageUrl(string $token): string
    {
        return 'https://api.telegram.org/bot'.$token.'/sendMessage';
    }
}
