<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SecurityScannerService
{
    protected MikrotikService $mikrotik;
    protected AlertService $alertService;

    public function __construct(MikrotikService $mikrotik, AlertService $alertService)
    {
        $this->mikrotik = $mikrotik;
        $this->alertService = $alertService;
    }

    /**
     * Run full security & performance anomaly scan across live MikroTik & CIMS inventory.
     */
    public function runFullScan(): array
    {
        $alerts = [];

        // 1. Scan MikroTik Live Logs for Security Anomalies (Brute Force, Auth Failures, Link Down)
        $logAlerts = $this->scanMikrotikLogAnomalies();
        $alerts = array_merge($alerts, $logAlerts);

        // 2. Scan Live MikroTik CPU, RAM, Disk Overload
        $resourceAlerts = $this->scanResourceAnomalies();
        $alerts = array_merge($alerts, $resourceAlerts);

        // 3. Scan CIMS Inventory Devices for Offline/Unreachable status
        $deviceAlerts = $this->scanDeviceConnectivity();
        $alerts = array_merge($alerts, $deviceAlerts);

        // 4. Scan Recent CIMS Web Portal Admin Logins
        $webLoginAlerts = $this->scanWebUserLogins();
        $alerts = array_merge($alerts, $webLoginAlerts);

        // Dispatch Telegram alert ONLY for NEW CRITICAL threats (Deduplicated via Cache)
        foreach ($alerts as $a) {
            if (($a['severity'] ?? '') === 'CRITICAL') {
                $sentCacheKey = 'telegram_sent_alert_' . ($a['id'] ?? md5(json_encode($a)));
                if (!Cache::has($sentCacheKey)) {
                    $this->alertService->dispatchAlert(
                        $a['device'] ?? 'MikroTik Gateway',
                        $a['category'] ?? 'CRITICAL_SECURITY_ALERT',
                        $a['title'] . ': ' . $a['message']
                    );
                    // Prevent resending identical alert for 24 hours
                    Cache::put($sentCacheKey, true, now()->addHours(24));
                }
            }
        }

        // Cache the latest scan results for 5 minutes
        Cache::put('cims_security_alerts', $alerts, now()->addMinutes(5));

        return $alerts;
    }

    /**
     * Audit recent CIMS Web Portal user login events.
     */
    public function scanWebUserLogins(): array
    {
        $alerts = [];
        $logPath = storage_path('logs/laravel.log');

        if (file_exists($logPath)) {
            $lines = array_slice(file($logPath), -200);
            foreach ($lines as $line) {
                if (str_contains($line, 'INFO_USER_LOGIN')) {
                    preg_match('/User\s+\'([^\']+)\'\s+\(([^\)]+)\)\s+logged\s+in\s+successfully\s+from\s+IP:\s+(\S+)/i', $line, $matches);
                    if (!empty($matches)) {
                        $alerts[] = [
                            'id' => 'alert-web-login-' . md5($line),
                            'severity' => 'INFO',
                            'category' => 'CIMS Web Audit Login',
                            'title' => "CIMS Web Login: {$matches[1]}",
                            'device' => 'CIMS Web Application',
                            'message' => "User {$matches[1]} ({$matches[2]}) logged in from IP address {$matches[3]}.",
                            'timestamp' => now()->toDateTimeString(),
                            'login_ip' => $matches[3],
                            'username' => $matches[1],
                            'suggestion' => "Active web session initiated from client IP address {$matches[3]}.",
                        ];
                    }
                }
            }
        }

        return $alerts;
    }

    /**
     * Scan MikroTik logs for brute force, unauthorized access, and interface drops.
     */
    public function scanMikrotikLogAnomalies(): array
    {
        $connection = $this->mikrotik->testConnection();
        if (!$connection['success']) {
            return [[
                'id' => 'alert-mikrotik-offline',
                'severity' => 'CRITICAL',
                'category' => 'Core Connection Loss',
                'title' => 'MikroTik Gateway Disconnected',
                'device' => config('services.mikrotik.host'),
                'message' => 'Unable to establish RouterOS API connection to primary gateway.',
                'timestamp' => now()->toDateTimeString(),
                'suggestion' => 'Check physical ethernet cabling, API port 7111 status, or admin credentials in .env file.',
            ]];
        }

        $logs = $this->mikrotik->getLogs();
        $alerts = [];
        $failedLoginsByIp = [];

        foreach ($logs as $entry) {
            $msg = strtolower($entry['message'] ?? '');
            $topics = strtolower($entry['topics'] ?? '');

            // Detect Login Failures (SSH / Winbox / Web / FTP)
            if (str_contains($msg, 'login failure') || str_contains($msg, 'authentication failed') || str_contains($msg, 'invalid password')) {
                // Extract IP address from log message if present
                preg_match('/from\s+([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)/i', $msg, $matches);
                $attackerIp = $matches[1] ?? 'Unknown IP';

                if (!isset($failedLoginsByIp[$attackerIp])) {
                    $failedLoginsByIp[$attackerIp] = 0;
                }
                $failedLoginsByIp[$attackerIp]++;
            }

            // Detect Successful Administrative Logins (Winbox / SSH / Web / API / Account Audit)
            if (str_contains($msg, 'logged in') || str_contains($msg, 'login success') || str_contains($topics, 'account')) {
                preg_match('/user\s+(\S+)\s+logged\s+in(?:\s+from\s+([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+))?(?:\s+via\s+(\S+))?/i', $entry['message'] ?? '', $matches);
                
                $username = !empty($matches[1]) ? $matches[1] : 'admin';
                $loginIp = !empty($matches[2]) ? $matches[2] : (preg_match('/from\s+([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)/i', $msg, $m) ? $m[1] : 'Internal / Winbox');
                $via = !empty($matches[3]) ? strtoupper($matches[3]) : (str_contains($msg, 'winbox') ? 'WINBOX' : 'SSH/WEB');

                $alerts[] = [
                    'id' => 'alert-user-login-' . md5($entry['message'] . ($entry['time'] ?? '')),
                    'severity' => 'INFO',
                    'category' => 'Winbox & RouterOS Login Audit',
                    'title' => "Successful Winbox/Admin Login ($username)",
                    'device' => $connection['identity'] ?? 'MikroTik Core Router',
                    'message' => "User '$username' logged into RouterOS via $via from IP address $loginIp.",
                    'timestamp' => $entry['time'] ?? now()->toTimeString(),
                    'login_ip' => $loginIp,
                    'username' => $username,
                    'via' => $via,
                    'suggestion' => "Verify if login from IP $loginIp via $via is an authorized PUSTIK network session.",
                ];
            }

            // Detect RouterOS System Log Errors & Critical System Anomalies
            if (str_contains($topics, 'error') || str_contains($topics, 'critical') || str_contains($msg, 'rebooted unexpectedly') || str_contains($msg, 'out of memory') || str_contains($msg, 'kernel crash')) {
                $alerts[] = [
                    'id' => 'alert-sys-error-' . md5($entry['message'] . ($entry['time'] ?? '')),
                    'severity' => 'CRITICAL',
                    'category' => 'System Log Error',
                    'title' => 'MikroTik System Error Anomaly',
                    'device' => $connection['identity'] ?? 'MikroTik Core Router',
                    'message' => "Critical System Event [{$entry['topics']}]: {$entry['message']}",
                    'timestamp' => $entry['time'] ?? now()->toTimeString(),
                    'suggestion' => 'Check RouterOS system log history, watchdog reboot logs, or hardware memory status.',
                ];
            }

            // Detect System Log Warnings (DHCP Exhaustion, DNS Full, Script Errors)
            if (str_contains($topics, 'warning') || str_contains($topics, 'script') || str_contains($msg, 'pool exhausted') || str_contains($msg, 'cache full')) {
                $alerts[] = [
                    'id' => 'alert-sys-warning-' . md5($entry['message'] . ($entry['time'] ?? '')),
                    'severity' => 'WARNING',
                    'category' => 'System Log Warning',
                    'title' => "System Warning [{$entry['topics']}]",
                    'device' => $connection['identity'] ?? 'MikroTik Core Router',
                    'message' => $entry['message'],
                    'timestamp' => $entry['time'] ?? now()->toTimeString(),
                    'suggestion' => 'Inspect RouterOS service configuration or resource pool allocations.',
                ];
            }

            // Detect Interface Link Drops
            if (str_contains($topics, 'interface') && (str_contains($msg, 'link down') || str_contains($msg, 'disconnected'))) {
                $alerts[] = [
                    'id' => 'alert-link-down-' . md5($entry['message']),
                    'severity' => 'WARNING',
                    'category' => 'Interface Failure',
                    'title' => 'Interface Link Disconnected',
                    'device' => $connection['identity'] ?? 'MikroTik Core Router',
                    'message' => $entry['message'],
                    'timestamp' => $entry['time'] ?? now()->toTimeString(),
                    'suggestion' => 'Inspect SFP transceiver, ethernet patch cord, or PoE switch power.',
                ];
            }

            // Detect Firewall Drop Warnings
            if (str_contains($topics, 'firewall') && (str_contains($msg, 'drop') || str_contains($msg, 'warning'))) {
                $alerts[] = [
                    'id' => 'alert-fw-drop-' . md5($entry['message']),
                    'severity' => 'INFO',
                    'category' => 'Firewall Event',
                    'title' => 'Firewall Rule Triggered',
                    'device' => $connection['identity'] ?? 'MikroTik Core Router',
                    'message' => $entry['message'],
                    'timestamp' => $entry['time'] ?? now()->toTimeString(),
                    'suggestion' => 'Verify if traffic matches legitimate application ports or suspicious port scans.',
                ];
            }
        }

        // Aggregate Brute Force Attack Attempts (Threshold: 10 - 50+ failed attempts = CRITICAL)
        foreach ($failedLoginsByIp as $ip => $attempts) {
            $isBruteForce = $attempts >= 10;
            $severity = $isBruteForce ? 'CRITICAL' : 'WARNING';
            $title = $isBruteForce
                ? "Login Brute Force Attack Detected ($attempts Attempts)"
                : "Suspicious Failed Login Attempts ($attempts Attempts)";

            $alertItem = [
                'id' => 'alert-brute-force-' . md5($ip . '-' . $attempts),
                'severity' => $severity,
                'category' => 'Security Brute Force',
                'title' => $title,
                'device' => $connection['identity'] ?? 'MikroTik Core Router',
                'message' => "Detected $attempts failed administrative login attempts from IP address $ip via SSH/Winbox/Web/API.",
                'timestamp' => now()->toDateTimeString(),
                'attacker_ip' => $ip,
                'attempts' => $attempts,
                'suggestion' => "Add IP $ip to MikroTik /ip firewall filter drop list or block port 22/8291 WAN access.",
            ];

            $alerts[] = $alertItem;
        }

        return $alerts;
    }

    /**
     * Scan CPU & Memory resource usage for overload thresholds.
     */
    public function scanResourceAnomalies(): array
    {
        $metrics = $this->mikrotik->getSystemMetrics();
        if (empty($metrics)) return [];

        $cpu = (int) ($metrics['cpu'] ?? 0);
        $ram = (int) ($metrics['ram'] ?? 0);

        $alerts = [];

        if ($cpu >= 80) {
            $severity = $cpu >= 90 ? 'CRITICAL' : 'WARNING';
            $alerts[] = [
                'id' => 'alert-cpu-high',
                'severity' => $severity,
                'category' => 'System Performance',
                'title' => "High CPU Load Spike ({$cpu}%)",
                'device' => config('services.mikrotik.host'),
                'message' => "Router CPU load is currently at {$cpu}%. Performance degraded.",
                'timestamp' => now()->toDateTimeString(),
                'suggestion' => 'Check RouterOS profile tools (/tool profile) to identify process hogging CPU cycles.',
            ];

            if ($severity === 'CRITICAL') {
                $this->alertService->dispatchAlert(
                    config('services.mikrotik.host'),
                    'CRITICAL_CPU_OVERLOAD',
                    "Router CPU usage spiked to {$cpu}%."
                );
            }
        }

        if ($ram >= 85) {
            $alerts[] = [
                'id' => 'alert-ram-high',
                'severity' => 'WARNING',
                'category' => 'System Memory',
                'title' => "High Memory Usage ({$ram}%)",
                'device' => config('services.mikrotik.host'),
                'message' => "RAM memory utilization reached {$ram}%.",
                'timestamp' => now()->toDateTimeString(),
                'suggestion' => 'Clear inactive DNS cache (/ip dns cache flush) or reboot router if memory leak persists.',
            ];
        }

        return $alerts;
    }

    /**
     * Scan Inventory devices marked offline.
     */
    public function scanDeviceConnectivity(): array
    {
        $offlineDevices = Device::where('status', 'offline')->get();
        $alerts = [];

        foreach ($offlineDevices as $dev) {
            $alerts[] = [
                'id' => 'alert-device-offline-' . $dev->id,
                'severity' => 'WARNING',
                'category' => 'Device Down',
                'title' => "Device Unreachable: {$dev->name}",
                'device' => $dev->name,
                'message' => "Device {$dev->name} (IP: {$dev->ip_address}) is marked OFFLINE in CIMS inventory.",
                'timestamp' => now()->toDateTimeString(),
                'suggestion' => "Verify power outlet in {$dev->building?->name}, room {$dev->room?->name}, and check uplink switch port.",
            ];
        }

        return $alerts;
    }

    /**
     * Trigger a test notification via Telegram Bot.
     */
    public function testTelegramAlert(): array
    {
        $token = env('TELEGRAM_BOT_TOKEN');
        $chatId = env('TELEGRAM_CHAT_ID');

        if (!$token || !$chatId) {
            return [
                'success' => false,
                'message' => 'TELEGRAM_BOT_TOKEN or TELEGRAM_CHAT_ID is not configured in .env file.',
            ];
        }

        try {
            $this->alertService->dispatchAlert(
                'CIMS Security Scanner',
                'TEST_NOTIFICATION',
                'Testing CIMS Telegram Security Alert Engine integration. Systems operational.'
            );
            return [
                'success' => true,
                'message' => 'Test notification successfully sent to Telegram Bot / Admin Group!',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed sending Telegram message: ' . $e->getMessage(),
            ];
        }
    }
}
