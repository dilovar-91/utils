<?php

namespace App\Services;

use App\Mail\AdminAlertMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AdminMailService
{
    public function send(string $message): bool
    {
        $recipients = $this->recipients();

        if ($recipients === []) {
            return false;
        }

        try {
            Mail::to($recipients)->send(new AdminAlertMail(
                $this->subject($message),
                nl2br($message, false),
            ));

            return true;
        } catch (Throwable $exception) {
            Log::warning('Admin email notification failed', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return list<string>
     */
    protected function recipients(): array
    {
        $parts = preg_split('/[,;]+/', (string) config('mail.admin')) ?: [];

        $emails = [];

        foreach ($parts as $part) {
            $email = trim($part);

            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    protected function subject(string $message): string
    {
        $plain = trim(html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $line = trim(strtok($plain, "\n") ?: '');

        if ($line === '') {
            $line = (string) config('app.name', 'Уведомление');
        }

        return mb_substr($line, 0, 150);
    }
}
