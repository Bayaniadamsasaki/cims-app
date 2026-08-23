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

    /**
     * Scan keamanan menembak perangkat & cloud API, jadi biayanya sepuluhan detik.
     * Kalau dijalankan inline, klik menu "Security Alerts" tidak menghasilkan apa
     * pun selama itu dan terasa macet. Karena itu hasil scan dikirim sebagai
     * deferred prop (Inertia v2): shell halaman langsung tampil, datanya menyusul
     * lewat request lanjutan.
     */
    public function index()
    {
        // Satu scan dipakai bersama `alerts` dan `stats`. Keduanya berada di grup
        // deferred yang sama sehingga closure ini dievaluasi dalam satu request.
        $scan = null;
        $alerts = function () use (&$scan) {
            return $scan ??= $this->scanner->runFullScan();
        };

        return Inertia::render('Alerts/Index', [
            'alerts'         => Inertia::defer($alerts),
            'stats'          => Inertia::defer(fn() => $this->summarize($alerts())),
            'telegramStatus' => [
                'configured' => !empty(env('TELEGRAM_BOT_TOKEN')) && !empty(env('TELEGRAM_CHAT_ID')),
                'bot_token'  => env('TELEGRAM_BOT_TOKEN') ? '••••••••' . substr(env('TELEGRAM_BOT_TOKEN'), -6) : null,
                'chat_id'    => env('TELEGRAM_CHAT_ID') ?? null,
            ],
        ]);
    }

    private function summarize(array $alerts): array
    {
        return [
            'total_alerts'   => count($alerts),
            'critical_count' => count(array_filter($alerts, fn($a) => ($a['severity'] ?? '') === 'CRITICAL')),
            'warning_count'  => count(array_filter($alerts, fn($a) => ($a['severity'] ?? '') === 'WARNING')),
            'info_count'     => count(array_filter($alerts, fn($a) => ($a['severity'] ?? '') === 'INFO')),
        ];
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
