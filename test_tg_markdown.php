<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$token = env('TELEGRAM_BOT_TOKEN');
$chatId = env('TELEGRAM_CHAT_ID');

$type = 'INFO_USER_LOGIN';
$deviceName = 'CIMS Web Portal';
$message = "User 'PUSTIK UBG' (pustikubg26@gmail.com) logged in successfully from IP: 127.0.0.1";

$text = "⚠️ *CIMS INFRASTRUCTURE ALERT*\n"
      . "*Type*: {$type}\n"
      . "*Device*: {$deviceName}\n"
      . "*Message*: {$message}\n"
      . "*Time*: " . now()->toDateTimeString();

echo "Sending to Telegram with Markdown...\n";
$response = \Illuminate\Support\Facades\Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
    'chat_id' => $chatId,
    'text' => $text,
    'parse_mode' => 'Markdown',
]);

echo "Status: " . $response->status() . "\n";
echo "Response: " . $response->body() . "\n";
