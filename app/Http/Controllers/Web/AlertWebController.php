<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\SecurityScannerService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AlertWebController extends Controller
{
    protected SecurityScannerService $scanner;

    public function __construct(SecurityScannerService $scanner)
    {
        $this->scanner = $scanner;
    }

    public function index()
    {
        $alerts = $this->scanner->runFullScan();

        $stats = [
            'total_alerts'   => count($alerts),
            'critical_count' => count(array_filter($alerts, fn($a) => ($a['severity'] ?? '') === 'CRITICAL')),
            'warning_count'  => count(array_filter($alerts, fn($a) => ($a['severity'] ?? '') === 'WARNING')),
            'info_count'     => count(array_filter($alerts, fn($a) => ($a['severity'] ?? '') === 'INFO')),
        ];

        return Inertia::render('Alerts/Index', [
            'alerts'         => $alerts,
            'stats'          => $stats,
            'telegramStatus' => [
                'configured' => !empty(env('TELEGRAM_BOT_TOKEN')) && !empty(env('TELEGRAM_CHAT_ID')),
                'bot_token'  => env('TELEGRAM_BOT_TOKEN') ? '••••••••' . substr(env('TELEGRAM_BOT_TOKEN'), -6) : null,
                'chat_id'    => env('TELEGRAM_CHAT_ID') ?? null,
            ],
        ]);
    }

    public function scan()
    {
        $alerts = $this->scanner->runFullScan();
        return response()->json([
            'success' => true,
            'alerts'  => $alerts,
            'count'   => count($alerts),
        ]);
    }

    public function testTelegram()
    {
        $res = $this->scanner->testTelegramAlert();
        return response()->json($res);
    }
}
