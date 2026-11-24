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
            $error = $exception->getMessage();
            $password = (string) config('mail.mailers.smtp.password');

            if ($password !== '') {
                $error = str_replace($password, '[redacted]', $error);
            }

            Log::warning('Admin email notification failed: '.$error, [
                'mailer' => config('mail.default'),
                'host' => config('mail.mailers.smtp.host'),
                'port' => config('mail.mailers.smtp.port'),
                'from' => config('mail.from.address'),
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
