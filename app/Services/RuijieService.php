<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RuijieService
{
    protected string $appId;
    protected string $secret;
    protected string $baseUrl;
    protected int $timeout;

    public function __construct()
    {
        $this->appId = config('services.ruijie.app_id', 'open2a30c702449b');
        $this->secret = config('services.ruijie.secret', '779af05e4ece46308add65013a8154c1');
        $this->baseUrl = rtrim(config('services.ruijie.base_url', 'https://cloud-as.ruijienetworks.com'), '/');
        $this->timeout = (int) config('services.ruijie.timeout', 15);
    }

    /**
     * Get or refresh OAuth Access Token from Ruijie Cloud Open API.
     */
    public function getAccessToken(): ?string
    {
        if (empty($this->appId) || empty($this->secret)) {
            Log::warning("Ruijie Cloud API credentials missing in .env.");
            return null;
        }

        $cacheKey = "ruijie_access_token_" . md5($this->appId);

        return Cache::remember($cacheKey, 3000, function () {
            try {
                // Primary OAuth Client Credentials endpoint
                $endpoint = "{$this->baseUrl}/service/api/oauth20/client/access_token";
                
                $response = Http::timeout($this->timeout)
                    ->withoutVerifying()
                    ->post($endpoint, [
                        'appid' => $this->appId,
                        'secret' => $this->secret,
                    ]);

                if ($response->successful()) {
                    $json = $response->json();
                    if (isset($json['accessToken'])) {
                        return $json['accessToken'];
                    }
                    if (isset($json['access_token'])) {
                        return $json['access_token'];
                    }
                    if (isset($json['data']['accessToken'])) {
                        return $json['data']['accessToken'];
                    }
                }

                // Fallback secondary endpoint
                $fallbackEndpoint = "{$this->baseUrl}/api/open/token";
                $fallbackRes = Http::timeout($this->timeout)
                    ->withoutVerifying()
                    ->post($fallbackEndpoint, [
                        'appId' => $this->appId,
                        'secret' => $this->secret,
                    ]);

                if ($fallbackRes->successful()) {
                    $json = $fallbackRes->json();
                    return $json['data']['token'] ?? $json['accessToken'] ?? null;
                }

                Log::error("Ruijie Cloud Auth Failed: " . $response->body());
                return null;
            } catch (\Exception $e) {
                Log::error("Ruijie Cloud Auth Exception: " . $e->getMessage());
                return null;
            }
        });
    }

    /**
     * Test connection to Ruijie Reyee Cloud Open API.
     */
    public function testConnection(): array
    {
        $startTime = microtime(true);
        $endpoint = "{$this->baseUrl}/service/api/oauth20/client/access_token";

        try {
            $response = Http::timeout($this->timeout)
                ->withoutVerifying()
                ->asJson()
                ->post($endpoint, [
                    'appid' => $this->appId,
                    'secret' => $this->secret,
                ]);

            $latency = round((microtime(true) - $startTime) * 1000, 2);
            $json = $response->json();

            $token = $json['accessToken'] ?? $json['access_token'] ?? $json['data']['accessToken'] ?? null;

            if ($token) {
                return [
                    'success' => true,
                    'message' => 'Ruijie Reyee Cloud API Connected Successfully',
                    'app_id' => $this->appId,
                    'latency_ms' => $latency,
                    'token' => substr($token, 0, 10) . '...',
                    'base_url' => $this->baseUrl,
                    'error_code' => 0,
                ];
            }

            $errorCode = $json['code'] ?? null;
            $errorMsg = $json['msg'] ?? $json['message'] ?? 'Failed connecting to Ruijie Reyee Cloud API.';

            $customHint = null;
            if ($errorCode == 5 || str_contains(strtolower($errorMsg), 'permission')) {
                $customHint = "APPID '{$this->appId}' belum di-Authorize di Portal Ruijie Cloud. Silakan buka Portal Ruijie Cloud -> Project Settings -> Open API -> Authorize APPID '{$this->appId}' ini.";
            }

            return [
                'success' => false,
                'message' => $errorMsg,
                'hint' => $customHint,
                'app_id' => $this->appId,
                'latency_ms' => $latency,
                'token' => null,
                'base_url' => $this->baseUrl,
                'error_code' => $errorCode,
            ];
        } catch (\Exception $e) {
            $latency = round((microtime(true) - $startTime) * 1000, 2);
            return [
                'success' => false,
                'message' => 'Exception connecting to Ruijie API: ' . $e->getMessage(),
                'app_id' => $this->appId,
                'latency_ms' => $latency,
                'token' => null,
                'base_url' => $this->baseUrl,
                'error_code' => 500,
            ];
        }
    }

    /**
     * Get Device List from Ruijie Cloud API combined with local inventory devices.
     */
    public function getDeviceList(): array
    {
        $token = $this->getAccessToken();
        $cloudDevices = [];

        if ($token) {
            try {
                $endpoint = "{$this->baseUrl}/service/api/device/list";
                $res = Http::timeout($this->timeout)
                    ->withoutVerifying()
                    ->get($endpoint, [
                        'accessToken' => $token,
                    ]);

                if ($res->successful()) {
                    $json = $res->json();
                    $items = $json['data']['list'] ?? $json['data'] ?? [];
                    if (is_array($items)) {
                        foreach ($items as $item) {
                            // Hanya field yang benar-benar dikirim API yang dipakai.
                            // Field yang tidak ada dibiarkan null agar UI bisa
                            // menampilkannya sebagai "tidak tersedia".
                            $cloudDevices[] = [
                                'sn' => $item['sn'] ?? $item['serialNumber'] ?? null,
                                'name' => $item['deviceName'] ?? $item['name'] ?? null,
                                'model' => $item['model'] ?? null,
                                'mac' => $item['mac'] ?? $item['macAddress'] ?? null,
                                'ip' => $item['ip'] ?? $item['ipAddress'] ?? null,
                                'status' => isset($item['status'])
                                    ? (strtolower((string) $item['status']) === 'online' ? 'online' : 'offline')
                                    : 'unknown',
                                'type' => $item['productType'] ?? $item['type'] ?? null,
                                'client_count' => isset($item['userCount']) || isset($item['onlineClients'])
                                    ? (int) ($item['userCount'] ?? $item['onlineClients'])
                                    : null,
                                'firmware' => $item['firmware'] ?? null,
                                'group_name' => $item['groupName'] ?? null,
                                'uptime' => $item['uptime'] ?? null,
                                'source' => 'Cloud API',
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Ruijie Cloud API fetch devices warning: " . $e->getMessage());
            }
        }

        // Merge or Fallback to Local Ruijie/Reyee Devices from Database Inventory
        $dbRuijieDevices = Device::with(['building', 'category', 'vendor', 'metrics'])
            ->where(function ($q) {
                $q->whereHas('vendor', fn($v) => $v->where('name', 'like', '%ruijie%')->orWhere('name', 'like', '%reyee%'))
                  ->orWhereHas('category', fn($c) => $c->where('name', 'like', '%access point%')->orWhere('name', 'like', '%wifi%'))
                  ->orWhere('name', 'like', '%ruijie%')
                  ->orWhere('name', 'like', '%reyee%')
                  ->orWhere('name', 'like', '%ap-%');
            })->get();

        $mergedDevices = [];
        $addedSns = [];

        foreach ($cloudDevices as $cd) {
            $mergedDevices[] = $cd;
            $addedSns[] = strtolower((string) ($cd['sn'] ?? ''));
        }

        foreach ($dbRuijieDevices as $dev) {
            $snKey = strtolower($dev->serial_number ?? $dev->ip_address ?? $dev->id);
            if (!in_array($snKey, $addedSns)) {
                $addedSns[] = $snKey;
                $uptimeSeconds = $dev->metrics?->last_uptime_seconds;
                $mergedDevices[] = [
                    'sn' => $dev->serial_number,
                    'name' => $dev->name,
                    'model' => $dev->model,
                    'mac' => $dev->mac_address,
                    'ip' => $dev->ip_address,
                    // Status berasal dari hasil monitoring nyata; perangkat yang
                    // belum pernah dicek ditandai 'unknown'.
                    'status' => $dev->metrics?->last_ping_status ?? 'unknown',
                    'type' => $dev->category->name ?? null,
                    // Jumlah klien Wi-Fi hanya ada di Ruijie Cloud, tidak di
                    // inventaris — jadi dibiarkan tidak diketahui.
                    'client_count' => null,
                    'firmware' => $dev->firmware,
                    'group_name' => $dev->building->name ?? null,
                    'uptime' => $uptimeSeconds !== null ? round($uptimeSeconds / 3600) . ' hours' : null,
                    'source' => 'DB Inventory',
                ];
            }
        }

        return $mergedDevices;
    }

    /**
     * Get Connected Wireless Clients (STAs) across Ruijie APs.
     */
    public function getWirelessClients(): array
    {
        $token = $this->getAccessToken();
        
        if ($token) {
            try {
                $endpoint = "{$this->baseUrl}/service/api/client/list";
                $res = Http::timeout($this->timeout)
                    ->withoutVerifying()
                    ->get($endpoint, ['accessToken' => $token]);

                if ($res->successful()) {
                    $json = $res->json();
                    $items = $json['data']['list'] ?? $json['data'] ?? [];
                    if (is_array($items) && !empty($items)) {
                        $clients = [];
                        foreach ($items as $c) {
                            $band = $c['band'] ?? null;
                            if ($band === null && isset($c['freq'])) {
                                $band = (int) $c['freq'] === 5 ? '5GHz' : '2.4GHz';
                            }

                            $clients[] = [
                                'mac' => $c['mac'] ?? $c['macAddress'] ?? null,
                                'ip' => $c['ip'] ?? $c['ipAddress'] ?? null,
                                'hostname' => $c['name'] ?? $c['hostname'] ?? null,
                                'ssid' => $c['ssid'] ?? null,
                                'ap_name' => $c['apName'] ?? $c['deviceName'] ?? null,
                                'rssi' => isset($c['rssi']) || isset($c['signal'])
                                    ? (int) ($c['rssi'] ?? $c['signal'])
                                    : null,
                                'band' => $band,
                                'tx_rate' => $c['txRate'] ?? null,
                                'rx_rate' => $c['rxRate'] ?? null,
                                'online_time' => $c['onlineTime'] ?? null,
                            ];
                        }

                        return $clients;
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Ruijie Cloud API clients fetch warning: " . $e->getMessage());
            }
        }

        // Daftar klien Wi-Fi hanya bisa datang dari Ruijie Cloud. Kalau API-nya
        // tidak terjangkau atau belum diauthorize, hasilnya kosong — tidak ada
        // klien karangan yang ditampilkan sebagai perangkat yang benar-benar
        // terhubung.
        return [];
    }

    /**
     * Get Active Network Alarms / Security Events from Ruijie Cloud.
     */
    public function getAlarms(): array
    {
        $token = $this->getAccessToken();
        
        if ($token) {
            try {
                $endpoint = "{$this->baseUrl}/service/api/alarm/list";
                $res = Http::timeout($this->timeout)
                    ->withoutVerifying()
                    ->get($endpoint, ['accessToken' => $token]);

                if ($res->successful()) {
                    $json = $res->json();
                    $items = $json['data']['list'] ?? $json['data'] ?? [];
                    if (is_array($items) && !empty($items)) {
                        return $items;
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Ruijie Cloud API alarms fetch warning: " . $e->getMessage());
            }
        }

        // Alarm hanya berasal dari Ruijie Cloud. Tanpa respons nyata, daftarnya
        // kosong — alarm contoh tidak pernah dibuat sendiri.
        return [];
    }
}
