<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AlertService
{
    /**
     * Dispatch an infrastructure alert through all active channels.
     */
    public function dispatchAlert(string $deviceName, string $alertType, string $message): void
    {
        // 1. Always log the incident locally
        Log::warning("[CIMS ALERT] [{$alertType}] Device: {$deviceName} - {$message}");

        // 2. Dispatch Telegram Bot Message (if credentials exist)
        $telegramToken = env('TELEGRAM_BOT_TOKEN');
        $telegramChatId = env('TELEGRAM_CHAT_ID');
        if ($telegramToken && $telegramChatId) {
            $this->sendTelegramNotification($telegramToken, $telegramChatId, $deviceName, $alertType, $message);
        }

        // 3. Dispatch Email Alert (if configured)
        $alertEmail = env('ALERT_RECEIVER_EMAIL');
        if ($alertEmail) {
            $this->sendEmailNotification($alertEmail, $deviceName, $alertType, $message);
        }

        // 4. Dispatch Webhook Notification (if URL exists)
        $webhookUrl = env('ALERT_WEBHOOK_URL');
        if ($webhookUrl) {
            $this->sendWebhookNotification($webhookUrl, $deviceName, $alertType, $message);
        }
    }

    /**
     * Send Telegram message.
     */
    protected function sendTelegramNotification(string $token, string $chatId, string $deviceName, string $type, string $message): void
    {
        $safeType = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');
        $safeDevice = htmlspecialchars($deviceName, ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $timeStr = now()->toDateTimeString();

        $text = "<b>🚨 CIMS INFRASTRUCTURE ALERT</b>\n\n"
              . "<b>Type:</b> <code>{$safeType}</code>\n"
              . "<b>Device:</b> <code>{$safeDevice}</code>\n"
              . "<b>Message:</b> {$safeMessage}\n"
              . "<b>Time:</b> <code>{$timeStr}</code>";

        try {
            Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);
        } catch (\Exception $e) {
            Log::error("Failed sending Telegram alert: " . $e->getMessage());
        }
    }

    /**
     * Send Email.
     */
    protected function sendEmailNotification(string $email, string $deviceName, string $type, string $message): void
    {
        try {
            Mail::raw("CIMS ALERT: {$type} detected on device {$deviceName}. Details: {$message}", function ($mail) use ($email, $deviceName, $type) {
                $mail->to($email)
                     ->subject("[CIMS ALERT] [{$type}] - {$deviceName}");
            });
        } catch (\Exception $e) {
            Log::error("Failed sending Email alert: " . $e->getMessage());
        }
    }

    /**
     * Send Webhook.
     */
    protected function sendWebhookNotification(string $url, string $deviceName, string $type, string $message): void
    {
        try {
            Http::timeout(3)->post($url, [
                'event' => 'infrastructure_alert',
                'device' => $deviceName,
                'type' => $type,
                'message' => $message,
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::error("Failed sending Webhook alert: " . $e->getMessage());
        }
    }
}
