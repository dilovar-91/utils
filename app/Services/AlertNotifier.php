<?php

namespace App\Services;

class AlertNotifier
{
    public function __construct(
        private TelegramService $telegram,
        private AdminMailService $mail,
    ) {}

    public function sendTelegram(string $message): bool
    {
        return $this->telegram->sendMessage($message);
    }

    public function sendMail(string $message): bool
    {
        return $this->mail->send($message);
    }
}
