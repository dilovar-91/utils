<?php

namespace App\Services;

class AlertNotifier
{
    public function __construct(
        private TelegramService $telegram,
        private AdminMailService $mail,
    ) {}

    public function send(string $message): bool
    {
        $telegramSent = $this->telegram->sendMessage($message);
        $mailSent = $this->mail->send($message);

        return $telegramSent || $mailSent;
    }
}
