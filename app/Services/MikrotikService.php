<?php

namespace App\Services;

use App\Support\DeviceCredential;
use Illuminate\Support\Facades\Log;
use RouterOS\Client;
use RouterOS\Query;

class MikrotikService
{
    protected ?Client $client = null;

    /**
     * Sumber kredensial per host pada koneksi terakhir, untuk pesan error yang
     * bisa ditindaklanjuti. Login gagal biasanya bukan karena passwordnya salah,
     * tapi karena inventaris tidak punya baris ber-IP tujuan (mis. alamat router
     * bergeser), sehingga koneksi diam-diam memakai user global .env.
     *
     * @var array<string,string>
     */
    protected array $credentialSources = [];

    /**
     * Penyebab kegagalan pembacaan metrik per host. Monitoring perlu tahu
     * alasannya (timeout, login gagal, API mati) supaya kegagalan nyata tidak
     * tampil sebagai metrik kosong tanpa keterangan.
     *
     * @var array<string,string|null>
     */
    protected array $lastErrors = [];

    /**
     * Get (or lazily create) the RouterOS API client connection for any router.
     */
    public function client(?string $host = null, ?string $user = null, ?string $pass = null, ?int $port = null): Client
    {
        if ($this->client === null || $host !== null || $user !== null) {
            $config = config('services.mikrotik');

            $rawHost = $host ?? $config['host'];
            $targetHost = $rawHost;
            $targetUser = $user;
            $targetPass = $pass;
            $targetPort = $port;

            // 1. Support IP:Port syntax (e.g. "192.168.1.254:8729")
            if ($rawHost && str_contains($rawHost, ':')) {
                $parts = explode(':', $rawHost);
                $targetHost = $parts[0];
                $targetPort = (int)$parts[1];
            }

            // 2. Dynamic Database Lookup: Fetch credentials for target router from Device Inventory
            $source = ($targetUser !== null || $targetPass !== null)
                ? 'kredensial dari pemanggil'
                : null;

            if ($targetHost && ($targetUser === null || $targetPass === null)) {
                $device = \App\Models\Device::where('ip_address', $targetHost)->first();
                if ($device) {
                    if ($targetUser === null && !empty($device->username)) {
                        $targetUser = $device->username;
                    }
                    if ($targetPass === null) {
                        $targetPass = DeviceCredential::password($device);
                    }
                    $source = "kredensial inventaris #{$device->id} ({$device->name})";
                }
            }

            // 3. Fallback to default .env config
            $usedEnvFallback = $targetUser === null || $targetPass === null;
            $targetUser = $targetUser ?? $config['user'];
            $targetPass = $targetPass ?? $config['password'];
            $targetPort = $targetPort ?? $config['port'];

            if ($usedEnvFallback) {
                $source = $source
                    ? $source . ' + fallback .env MIKROTIK_USER'
                    : "kredensial dari .env MIKROTIK_USER — tidak ada perangkat inventaris dengan IP {$targetHost}";
            }

            if ($targetHost) {
                $this->credentialSources[$targetHost] = $source ?? 'kredensial dari pemanggil';
            }

            $client = new Client([
                'host' => $targetHost,
                'user' => $targetUser,
                'pass' => $targetPass,
                'port' => $targetPort,
                'ssl' => $config['ssl'],
                'ssl_options' => [
                    'ciphers' => 'ADH:ALL@SECLEVEL=0',
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
                'timeout' => $config['timeout'],
                'socket_timeout' => 5,
                'throw_timeout_exception' => false,
                'attempts' => $config['attempts'],
            ]);

            if ($host === null && $user === null) {
                $this->client = $client;
            }

            return $client;
        }

        return $this->client;
    }

    /**
     * Host tanpa bagian ":port", memakai default config kalau tidak disebut —
     * supaya penulisan dan pembacaan credentialSources memakai kunci yang sama.
     */
    protected function hostKey(?string $host): string
    {
        $raw = $host ?? (string) config('services.mikrotik.host');

        return str_contains($raw, ':') ? explode(':', $raw)[0] : $raw;
    }

    /**
     * Kredensial mana yang dipakai untuk host ini pada koneksi terakhir.
     */
    public function credentialSourceFor(?string $host = null): ?string
    {
        return $this->credentialSources[$this->hostKey($host)] ?? null;
    }

    /**
     * Penyebab kegagalan pembacaan metrik terakhir untuk host ini, atau null
     * kalau pembacaan terakhirnya berhasil.
     */
    public function lastErrorFor(?string $host = null): ?string
    {
        return $this->lastErrors[$this->hostKey($host)] ?? null;
    }

    /**
     * Pesan RouterOS ini soal login, bukan soal host yang tidak terjangkau.
     */
    protected function looksLikeAuthFailure(string $message): bool
    {
        return (bool) preg_match('/user name or password|cannot log in|invalid user|not logged in/i', $message);
    }

    /**
     * Test connectivity to the router. Returns identity info or error.
     */
    public function testConnection(?string $host = null): array
    {
        try {
            $identity = $this->client($host)->query('/system/identity/print')->read();
            $resource = $this->client($host)->query('/system/resource/print')->read();

            return [
                'success' => true,
                'identity' => $identity[0]['name'] ?? 'unknown',
                'board' => $resource[0]['board-name'] ?? null,
                'version' => $resource[0]['version'] ?? null,
            ];
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            Log::warning("MikroTik connection failed: {$message}");

            // Kegagalan login sendiri tidak menyebutkan user mana yang dipakai,
            // jadi sumber kredensial ikut ditempel supaya penyebabnya kelihatan.
            $source = $this->credentialSourceFor($host);

            return [
                'success' => false,
                'error' => $source && $this->looksLikeAuthFailure($message)
                    ? "{$message} ({$source})"
                    : $message,
                'credential_source' => $source,
            ];
        }
    }

    /**
     * Fetch system resource metrics (CPU, RAM, storage, uptime, temperature).
     * Nilai yang tidak tersedia dikembalikan null; penyebab kegagalan bisa
     * dibaca lewat lastErrorFor($host).
     */
    public function getSystemMetrics(?string $host = null): array
    {
        $data = [
            'cpu' => null,
            'ram' => null,
            'storage' => null,
            'temp' => null,
            'uptime' => null,
            'rx' => null,
            'tx' => null,
            'interfaces' => [],
        ];

        $this->lastErrors[$this->hostKey($host)] = null;

        try {
            $client = $this->client($host);

            // /system/resource -> CPU, RAM, storage, uptime
            $resource = $client->query('/system/resource/print')->read()[0] ?? [];

            $data['cpu'] = isset($resource['cpu-load']) ? (int) $resource['cpu-load'] : null;

            if (isset($resource['total-memory'], $resource['free-memory']) && $resource['total-memory'] > 0) {
                $data['ram'] = (int) round((1 - $resource['free-memory'] / $resource['total-memory']) * 100);
            }

            if (isset($resource['total-hdd-space'], $resource['free-hdd-space']) && $resource['total-hdd-space'] > 0) {
                $data['storage'] = (int) round((1 - $resource['free-hdd-space'] / $resource['total-hdd-space']) * 100);
            }

            if (isset($resource['uptime'])) {
                $data['uptime'] = $this->parseUptime($resource['uptime']);
            }

            // /system/health -> temperature (not available on all boards)
            try {
                $health = $client->query('/system/health/print')->read();
                foreach ($health as $item) {
                    if (($item['name'] ?? '') === 'temperature' && isset($item['value'])) {
                        $data['temp'] = (int) $item['value'];
                    } elseif (isset($item['temperature'])) {
                        // Older RouterOS versions return flat structure
                        $data['temp'] = (int) $item['temperature'];
                    }
                }
            } catch (\Throwable $e) {
                // Board has no health sensors — ignore
            }

            // /interface -> status list + aggregate bandwidth from ethernet/wlan
            $interfaces = $client->query('/interface/print')->read();
            $totalRx = 0;
            $totalTx = 0;

            foreach ($interfaces as $iface) {
                if (($iface['type'] ?? '') === 'lo') {
                    continue;
                }

                $data['interfaces'][] = [
                    'name' => $iface['name'] ?? 'unknown',
                    'status' => ($iface['running'] ?? 'false') === 'true' ? 'up' : 'down',
                    'type' => $iface['type'] ?? null,
                    'mac' => $iface['mac-address'] ?? null,
                ];
            }

            // Live traffic on the busiest running interface via /interface/monitor-traffic
            $running = array_filter($interfaces, fn ($i) => ($i['running'] ?? 'false') === 'true' && ($i['type'] ?? '') !== 'lo');

            foreach ($running as $iface) {
                try {
                    $traffic = $client->query(
                        (new Query('/interface/monitor-traffic'))
                            ->equal('interface', $iface['name'])
                            ->equal('once', '')
                    )->read();

                    $totalRx += (int) ($traffic[0]['rx-bits-per-second'] ?? 0);
                    $totalTx += (int) ($traffic[0]['tx-bits-per-second'] ?? 0);
                } catch (\Throwable $e) {
                    // Skip interfaces that fail monitoring
                }
            }

            $data['rx'] = $totalRx;
            $data['tx'] = $totalTx;
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $source = $this->credentialSourceFor($host);

            // Kegagalan login tidak menyebutkan user mana yang dipakai, jadi
            // sumber kredensialnya ikut ditempel — tanpa nilai passwordnya.
            $this->lastErrors[$this->hostKey($host)] = $source && $this->looksLikeAuthFailure($message)
                ? "gagal: {$message} ({$source})"
                : "gagal: {$message}";

            Log::warning('MikroTik metrics fetch failed for ' . ($host ?? config('services.mikrotik.host')) . ": {$message}");
        }

        return $data;
    }

    /**
     * Get connected DHCP leases (active clients on the network).
     */
    public function getDhcpLeases(?string $host = null): array
    {
        try {
            $leases = $this->client($host)->query('/ip/dhcp-server/lease/print')->read();

            return array_map(fn ($lease) => [
                'address' => $lease['address'] ?? null,
                'mac' => $lease['mac-address'] ?? null,
                'hostname' => $lease['host-name'] ?? null,
                'status' => $lease['status'] ?? null,
                'last_seen' => $lease['last-seen'] ?? null,
            ], $leases);
        } catch (\Throwable $e) {
            Log::warning("MikroTik DHCP lease fetch failed: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * Get ARP table entries (neighbor discovery for network inventory).
     */
    public function getArpTable(?string $host = null): array
    {
        try {
            $entries = $this->client($host)->query('/ip/arp/print')->read();

            return array_map(fn ($entry) => [
                'address' => $entry['address'] ?? null,
                'mac' => $entry['mac-address'] ?? null,
                'interface' => $entry['interface'] ?? null,
                'complete' => ($entry['complete'] ?? 'false') === 'true',
            ], $entries);
        } catch (\Throwable $e) {
            Log::warning("MikroTik ARP fetch failed: {$e->getMessage()}");

            return [];
        }
    }

    // ──────────────────────────────────────────────────────
    //  IP  ADDRESSES  (/ip/address/print)
    // ──────────────────────────────────────────────────────
    /**
     * List all configured IP addresses on the router.
     * Replaces Winbox: IP → Addresses
     */
    public function getIpAddresses(?string $host = null): array
    {
        try {
            $rows = $this->client($host)->query('/ip/address/print')->read();

            return array_map(fn ($r) => [
                'address'   => $r['address'] ?? null,
                'network'   => $r['network'] ?? null,
                'interface' => $r['interface'] ?? null,
                'disabled'  => ($r['disabled'] ?? 'false') === 'true',
                'dynamic'   => ($r['dynamic'] ?? 'false') === 'true',
                'comment'   => $r['comment'] ?? null,
            ], $rows);
        } catch (\Throwable $e) {
            Log::warning("MikroTik IP Address fetch failed: {$e->getMessage()}");
            return [];
        }
    }

    // ──────────────────────────────────────────────────────
    //  ROUTES  (/ip/route/print)
    // ──────────────────────────────────────────────────────
    /**
     * Get the full routing table.
     * Replaces Winbox: IP → Routes
     */
    public function getRoutes(?string $host = null): array
    {
        try {
            $rows = $this->client($host)->query('/ip/route/print')->read();

            return array_map(fn ($r) => [
                'dst_address' => $r['dst-address'] ?? '0.0.0.0/0',
                'gateway'     => $r['gateway'] ?? null,
                'distance'    => $r['distance'] ?? null,
                'interface'   => $r['gateway-status'] ?? ($r['interface'] ?? null),
                'active'      => ($r['active'] ?? 'false') === 'true',
                'static'      => ($r['static'] ?? 'false') === 'true',
                'dynamic'     => ($r['dynamic'] ?? 'false') === 'true',
                'comment'     => $r['comment'] ?? null,
            ], $rows);
        } catch (\Throwable $e) {
            Log::warning("MikroTik routes fetch failed: {$e->getMessage()}");
            return [];
        }
    }

    // ──────────────────────────────────────────────────────
    //  FIREWALL FILTER  (/ip/firewall/filter/print)
    // ──────────────────────────────────────────────────────
    /**
     * Get all firewall filter rules.
     * Replaces Winbox: IP → Firewall → Filter Rules
     */
    public function getFirewallFilter(?string $host = null): array
    {
        try {
            $rows = $this->client($host)->query('/ip/firewall/filter/print')->read();

            return array_map(fn ($r) => [
                'chain'        => $r['chain'] ?? null,
                'action'       => $r['action'] ?? null,
                'protocol'     => $r['protocol'] ?? 'any',
                'src_address'  => $r['src-address'] ?? null,
                'dst_address'  => $r['dst-address'] ?? null,
                'dst_port'     => $r['dst-port'] ?? null,
                'in_interface' => $r['in-interface'] ?? null,
                'disabled'     => ($r['disabled'] ?? 'false') === 'true',
                'bytes'        => (int) ($r['bytes'] ?? 0),
                'packets'      => (int) ($r['packets'] ?? 0),
                'comment'      => $r['comment'] ?? null,
            ], $rows);
        } catch (\Throwable $e) {
            Log::warning("MikroTik firewall filter fetch failed: {$e->getMessage()}");
            return [];
        }
    }

    // ──────────────────────────────────────────────────────
    //  NAT RULES  (/ip/firewall/nat/print)
    // ──────────────────────────────────────────────────────
    /**
     * Get all NAT rules (masquerade, dst-nat, src-nat).
     * Replaces Winbox: IP → Firewall → NAT
     */
    public function getNatRules(?string $host = null): array
    {
        try {
            $rows = $this->client($host)->query('/ip/firewall/nat/print')->read();

            return array_map(fn ($r) => [
                'chain'          => $r['chain'] ?? null,
                'action'         => $r['action'] ?? null,
                'protocol'       => $r['protocol'] ?? 'any',
                'src_address'    => $r['src-address'] ?? null,
                'dst_address'    => $r['dst-address'] ?? null,
                'dst_port'       => $r['dst-port'] ?? null,
                'to_addresses'   => $r['to-addresses'] ?? null,
                'to_ports'       => $r['to-ports'] ?? null,
                'out_interface'  => $r['out-interface'] ?? null,
                'disabled'       => ($r['disabled'] ?? 'false') === 'true',
                'bytes'          => (int) ($r['bytes'] ?? 0),
                'comment'        => $r['comment'] ?? null,
            ], $rows);
        } catch (\Throwable $e) {
            Log::warning("MikroTik NAT rules fetch failed: {$e->getMessage()}");
            return [];
        }
    }

    // ──────────────────────────────────────────────────────
    //  HOTSPOT ACTIVE  (/ip/hotspot/active/print)
    // ──────────────────────────────────────────────────────
    /**
     * Get active hotspot users.
     * Replaces Winbox: IP → Hotspot → Active
     */
    public function getHotspotActive(?string $host = null): array
    {
        try {
            $rows = $this->client($host)->query('/ip/hotspot/active/print')->read();

            return array_map(fn ($r) => [
                'user'       => $r['user'] ?? null,
                'address'    => $r['address'] ?? null,
                'mac'        => $r['mac-address'] ?? null,
                'uptime'     => $r['uptime'] ?? null,
                'server'     => $r['server'] ?? null,
                'bytes_in'   => (int) ($r['bytes-in'] ?? 0),
                'bytes_out'  => (int) ($r['bytes-out'] ?? 0),
                'login_by'   => $r['login-by'] ?? null,
            ], $rows);
        } catch (\Throwable $e) {
            Log::warning("MikroTik hotspot active fetch failed: {$e->getMessage()}");
            return [];
        }
    }

    // ──────────────────────────────────────────────────────
    //  WIRELESS CLIENTS  (/interface/wireless/registration-table/print)
    // ──────────────────────────────────────────────────────
    /**
     * Get connected wireless clients (WiFi registration table).
     * Replaces Winbox: Wireless → Registration
     */
    public function getWirelessClients(?string $host = null): array
    {
        try {
            $rows = $this->client($host)->query('/interface/wireless/registration-table/print')->read();

            return array_map(fn ($r) => [
                'interface'     => $r['interface'] ?? null,
                'mac'           => $r['mac-address'] ?? null,
                'signal'        => $r['signal-strength'] ?? null,
                'signal_to_noise' => $r['signal-to-noise'] ?? null,
                'tx_rate'       => $r['tx-rate'] ?? null,
                'rx_rate'       => $r['rx-rate'] ?? null,
                'uptime'        => $r['uptime'] ?? null,
                'bytes'         => (int) ($r['bytes'] ?? 0),
                'last_ip'       => $r['last-ip'] ?? null,
            ], $rows);
        } catch (\Throwable $e) {
            // Some boards have no wireless — not an error
            Log::info("MikroTik wireless clients fetch: {$e->getMessage()}");
            return [];
        }
    }

    // ──────────────────────────────────────────────────────
    //  SYSTEM LOGS  (/log/print)
    // ──────────────────────────────────────────────────────
    /**
     * Get recent system log entries.
     * Replaces Winbox: Log
     *
     * @param int $limit  Maximum number of entries (newest first).
     */
    public function getLogs(?string $host = null, int $limit = 100): array
    {
        try {
            $rows = $this->client($host)->query('/log/print')->read();

            // RouterOS returns oldest-first, reverse for newest-first
            $rows = array_reverse($rows);
            $rows = array_slice($rows, 0, $limit);

            return array_map(fn ($r) => [
                'time'    => $r['time'] ?? null,
                'topics'  => $r['topics'] ?? null,
                'message' => $r['message'] ?? null,
            ], $rows);
        } catch (\Throwable $e) {
            Log::warning("MikroTik logs fetch failed: {$e->getMessage()}");
            return [];
        }
    }

    // ──────────────────────────────────────────────────────
    //  SIMPLE QUEUES  (/queue/simple/print)
    // ──────────────────────────────────────────────────────
    /**
     * Get simple queue list (bandwidth management).
     * Replaces Winbox: Queues → Simple Queues
     */
    public function getQueues(?string $host = null): array
    {
        try {
            $rows = $this->client($host)->query('/queue/simple/print')->read();

            return array_map(fn ($r) => [
                'name'          => $r['name'] ?? null,
                'target'        => $r['target'] ?? null,
                'max_limit'     => $r['max-limit'] ?? null,
                'burst_limit'   => $r['burst-limit'] ?? null,
                'bytes'         => $r['bytes'] ?? null,
                'packets'       => $r['packets'] ?? null,
                'rate'          => $r['rate'] ?? null,
                'disabled'      => ($r['disabled'] ?? 'false') === 'true',
                'comment'       => $r['comment'] ?? null,
            ], $rows);
        } catch (\Throwable $e) {
            Log::warning("MikroTik queues fetch failed: {$e->getMessage()}");
            return [];
        }
    }

    // ──────────────────────────────────────────────────────
    //  NEIGHBORS  (/ip/neighbor/print)
    // ──────────────────────────────────────────────────────
    /**
     * Discover network neighbors (CDP / MNDP / LLDP).
     * Replaces Winbox: IP → Neighbors
     */
    public function getNeighbors(?string $host = null): array
    {
        try {
            $rows = $this->client($host)->query('/ip/neighbor/print')->read();

            return array_map(fn ($r) => [
                'identity'    => $r['identity'] ?? null,
                'address'     => $r['address4'] ?? ($r['address'] ?? null),
                'mac'         => $r['mac-address'] ?? null,
                'interface'   => $r['interface'] ?? null,
                'platform'    => $r['platform'] ?? null,
                'board'       => $r['board'] ?? null,
                'version'     => $r['version'] ?? null,
                'system_description' => $r['system-description'] ?? null,
            ], $rows);
        } catch (\Throwable $e) {
            Log::warning("MikroTik neighbors fetch failed: {$e->getMessage()}");
            return [];
        }
    }

    // ──────────────────────────────────────────────────────
    //  PPP ACTIVE  (/ppp/active/print)
    // ──────────────────────────────────────────────────────
    /**
     * Get active PPP connections (PPPoE / PPTP / L2TP / SSTP / OVPN).
     * Replaces Winbox: PPP → Active Connections
     */
    public function getPppActive(?string $host = null): array
    {
        try {
            $rows = $this->client($host)->query('/ppp/active/print')->read();

            return array_map(fn ($r) => [
                'name'      => $r['name'] ?? null,
                'service'   => $r['service'] ?? null,
                'caller_id' => $r['caller-id'] ?? null,
                'address'   => $r['address'] ?? null,
                'uptime'    => $r['uptime'] ?? null,
                'encoding'  => $r['encoding'] ?? null,
                'session_id' => $r['session-id'] ?? null,
            ], $rows);
        } catch (\Throwable $e) {
            Log::warning("MikroTik PPP active fetch failed: {$e->getMessage()}");
            return [];
        }
    }

    // ──────────────────────────────────────────────────────
    //  DNS CONFIG  (/ip/dns/print)
    // ──────────────────────────────────────────────────────
    /**
     * Get DNS server configuration & cache statistics.
     * Replaces Winbox: IP → DNS
     */
    public function getDnsConfig(?string $host = null): array
    {
        try {
            $dns = $this->client($host)->query('/ip/dns/print')->read()[0] ?? [];

            return [
                'servers'              => $dns['servers'] ?? null,
                'dynamic_servers'      => $dns['dynamic-servers'] ?? null,
                'allow_remote'         => ($dns['allow-remote-requests'] ?? 'false') === 'true',
                'cache_size'           => $dns['cache-size'] ?? null,
                'cache_used'           => $dns['cache-used'] ?? null,
                'max_udp_packet_size'  => $dns['max-udp-packet-size'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::warning("MikroTik DNS config fetch failed: {$e->getMessage()}");
            return [];
        }
    }

    // ──────────────────────────────────────────────────────
    //  SYSTEM PACKAGES  (/system/package/print)
    // ──────────────────────────────────────────────────────
    /**
     * Get installed RouterOS packages & versions.
     * Replaces Winbox: System → Packages
     */
    public function getSystemPackages(?string $host = null): array
    {
        try {
            $rows = $this->client($host)->query('/system/package/print')->read();

            return array_map(fn ($r) => [
                'name'     => $r['name'] ?? null,
                'version'  => $r['version'] ?? null,
                'disabled' => ($r['disabled'] ?? 'false') === 'true',
                'scheduled' => $r['scheduled'] ?? null,
            ], $rows);
        } catch (\Throwable $e) {
            Log::warning("MikroTik packages fetch failed: {$e->getMessage()}");
            return [];
        }
    }

    // ──────────────────────────────────────────────────────
    //  ROUTER USERS  (/user/print)
    // ──────────────────────────────────────────────────────
    /**
     * Get router user accounts.
     * Replaces Winbox: System → Users
     */
    public function getUsers(?string $host = null): array
    {
        try {
            $rows = $this->client($host)->query('/user/print')->read();

            return array_map(fn ($r) => [
                'name'      => $r['name'] ?? null,
                'group'     => $r['group'] ?? null,
                'disabled'  => ($r['disabled'] ?? 'false') === 'true',
                'last_login' => $r['last-logged-in'] ?? null,
                'comment'   => $r['comment'] ?? null,
            ], $rows);
        } catch (\Throwable $e) {
            Log::warning("MikroTik users fetch failed: {$e->getMessage()}");
            return [];
        }
    }

    // ══════════════════════════════════════════════════════
    //  HOTSPOT USER MANAGEMENT  (/ip/hotspot/user)
    //  Dipakai fitur Voucher WiFi Mahasiswa. Berbeda dengan
    //  getter di atas, method tulis di sini SENGAJA melempar
    //  exception supaya kegagalan push bisa dicatat per NIM.
    // ══════════════════════════════════════════════════════

    /**
     * Daftar user profile hotspot (Winbox: IP → Hotspot → User Profiles).
     */
    public function getHotspotProfiles(?string $host = null): array
    {
        try {
            $rows = $this->client($host)->query('/ip/hotspot/user/profile/print')->read();

            return array_values(array_map(fn ($r) => [
                'name'            => $r['name'] ?? null,
                'rate_limit'      => $r['rate-limit'] ?? null,
                'shared_users'    => $r['shared-users'] ?? null,
                'session_timeout' => $r['session-timeout'] ?? null,
                'idle_timeout'    => $r['idle-timeout'] ?? null,
            ], array_filter($rows, fn ($r) => isset($r['name']))));
        } catch (\Throwable $e) {
            Log::warning("MikroTik hotspot profiles fetch failed: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Daftar hotspot server (Winbox: IP → Hotspot → Servers).
     */
    public function getHotspotServers(?string $host = null): array
    {
        try {
            $rows = $this->client($host)->query('/ip/hotspot/print')->read();

            return array_values(array_map(fn ($r) => [
                'name'      => $r['name'] ?? null,
                'interface' => $r['interface'] ?? null,
                'profile'   => $r['profile'] ?? null,
                'disabled'  => ($r['disabled'] ?? 'false') === 'true',
            ], array_filter($rows, fn ($r) => isset($r['name']))));
        } catch (\Throwable $e) {
            Log::warning("MikroTik hotspot servers fetch failed: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Daftar seluruh user hotspot yang terdaftar di router.
     */
    public function getHotspotUsers(?string $host = null): array
    {
        try {
            $rows = $this->client($host)->query('/ip/hotspot/user/print')->read();

            return array_values(array_map(fn ($r) => $this->mapHotspotUser($r), array_filter($rows, fn ($r) => isset($r['name']))));
        } catch (\Throwable $e) {
            Log::warning("MikroTik hotspot users fetch failed: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Cari satu user hotspot berdasarkan username (NIM). Null bila belum ada.
     */
    public function findHotspotUser(?string $host, string $name): ?array
    {
        $rows = $this->client($host)->query(
            (new Query('/ip/hotspot/user/print'))->where('name', $name)
        )->read();

        foreach ($rows as $row) {
            if (($row['name'] ?? null) === $name) {
                return $this->mapHotspotUser($row);
            }
        }

        return null;
    }

    /**
     * Buat user hotspot baru. Mengembalikan internal id RouterOS (mis. "*1A").
     *
     * @param array<string,string> $attributes Pasangan atribut RouterOS, mis. ['name' => '2101001', 'password' => '2101001'].
     */
    public function createHotspotUser(?string $host, array $attributes): string
    {
        $query = new Query('/ip/hotspot/user/add');

        foreach ($attributes as $key => $value) {
            $query->equal($key, $value);
        }

        $response = $this->writeQuery($host, $query);

        return (string) ($response['after']['ret'] ?? '');
    }

    /**
     * Ubah atribut user hotspot yang sudah ada berdasarkan internal id RouterOS.
     *
     * @param array<string,string> $attributes
     */
    public function updateHotspotUser(?string $host, string $id, array $attributes): void
    {
        $query = (new Query('/ip/hotspot/user/set'))->equal('.id', $id);

        foreach ($attributes as $key => $value) {
            $query->equal($key, $value);
        }

        $this->writeQuery($host, $query);
    }

    /**
     * Buat user bila belum ada, update bila sudah ada (idempoten per username).
     * Mengembalikan internal id RouterOS milik entri tersebut.
     *
     * @param array<string,string> $attributes Wajib memuat key 'name'.
     */
    public function upsertHotspotUser(?string $host, array $attributes): string
    {
        $name = $attributes['name'] ?? null;

        if (blank($name)) {
            throw new \InvalidArgumentException('Atribut "name" (username hotspot) wajib diisi.');
        }

        $existing = $this->findHotspotUser($host, $name);

        if ($existing === null) {
            return $this->createHotspotUser($host, $attributes);
        }

        // 'name' tidak perlu di-set ulang karena dipakai sebagai kunci pencarian.
        $payload = $attributes;
        unset($payload['name']);

        $this->updateHotspotUser($host, $existing['id'], $payload);

        return $existing['id'];
    }

    /**
     * Aktifkan / nonaktifkan user hotspot tanpa menghapusnya dari router.
     */
    public function setHotspotUserDisabled(?string $host, string $id, bool $disabled): void
    {
        $this->writeQuery($host, (new Query('/ip/hotspot/user/set'))
            ->equal('.id', $id)
            ->equal('disabled', $disabled ? 'yes' : 'no'));
    }

    /**
     * Hapus user hotspot dari router.
     */
    public function deleteHotspotUser(?string $host, string $id): void
    {
        $this->writeQuery($host, (new Query('/ip/hotspot/user/remove'))->equal('.id', $id));
    }

    /**
     * Putuskan sesi aktif milik satu username (Winbox: Hotspot → Active → Remove).
     * Mengembalikan jumlah sesi yang diputus.
     */
    public function kickHotspotActive(?string $host, string $user): int
    {
        $rows = $this->client($host)->query(
            (new Query('/ip/hotspot/active/print'))->where('user', $user)
        )->read();

        $kicked = 0;

        foreach ($rows as $row) {
            if (empty($row['.id'])) {
                continue;
            }

            $this->writeQuery($host, (new Query('/ip/hotspot/active/remove'))->equal('.id', $row['.id']));
            $kicked++;
        }

        return $kicked;
    }

    /**
     * Jalankan query tulis dan ubah balasan !trap RouterOS menjadi exception.
     * Library ini mengembalikan trap sebagai data biasa di ['after']['message'],
     * jadi tanpa pemeriksaan ini kegagalan akan terlihat seperti sukses.
     *
     * @return array<string,mixed>
     */
    protected function writeQuery(?string $host, Query $query): array
    {
        $response = $this->client($host)->query($query)->read();

        $message = $response['after']['message'] ?? null;

        if ($message !== null) {
            throw new \RuntimeException("RouterOS menolak perintah: {$message}");
        }

        if (isset($response[0]) && $response[0] === '!fatal') {
            throw new \RuntimeException('Koneksi RouterOS terputus saat menjalankan perintah.');
        }

        return $response;
    }

    /**
     * Normalisasi satu baris /ip/hotspot/user/print.
     *
     * @param array<string,string> $row
     * @return array<string,mixed>
     */
    protected function mapHotspotUser(array $row): array
    {
        return [
            'id'           => $row['.id'] ?? null,
            'name'         => $row['name'] ?? null,
            'password'     => $row['password'] ?? null,
            'profile'      => $row['profile'] ?? null,
            'server'       => $row['server'] ?? null,
            'limit_uptime' => $row['limit-uptime'] ?? null,
            'uptime'       => $row['uptime'] ?? null,
            'bytes_in'     => (int) ($row['bytes-in'] ?? 0),
            'bytes_out'    => (int) ($row['bytes-out'] ?? 0),
            'disabled'     => ($row['disabled'] ?? 'false') === 'true',
            'comment'      => $row['comment'] ?? null,
        ];
    }

    // ══════════════════════════════════════════════════════
    //  HELPER UTILITIES
    // ══════════════════════════════════════════════════════

    /**
     * Convert RouterOS uptime string (e.g. "2w3d4h5m6s") to seconds.
     */
    protected function parseUptime(string $uptime): int
    {
        $seconds = 0;
        $units = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];

        preg_match_all('/(\d+)([wdhms])/', $uptime, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $seconds += (int) $match[1] * $units[$match[2]];
        }

        return $seconds;
    }

    /**
     * Format bytes into human-readable string (KB, MB, GB).
     */
    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor((strlen((string) $bytes) - 1) / 3);

        return round($bytes / pow(1024, $factor), $precision) . ' ' . ($units[$factor] ?? 'B');
    }

    /**
     * Format bits-per-second into human-readable string (Kbps, Mbps, Gbps).
     */
    public static function formatBps(int $bps, int $precision = 2): string
    {
        if ($bps >= 1_000_000_000) {
            return round($bps / 1_000_000_000, $precision) . ' Gbps';
        }
        if ($bps >= 1_000_000) {
            return round($bps / 1_000_000, $precision) . ' Mbps';
        }
        if ($bps >= 1_000) {
            return round($bps / 1_000, $precision) . ' Kbps';
        }

        return $bps . ' bps';
    }

    /**
     * Fetch interfaces and IP addresses from MikroTik router and sync to database.
     */
    public function syncDeviceInterfaces(\App\Models\Device $device): int
    {
        if (!$device->ip_address) {
            throw new \Exception("IP Address perangkat tidak diisi.");
        }

        $client = $this->client(
            $device->ip_address,
            $device->username,
            DeviceCredential::password($device)
        );

        $interfaces = $client->query('/interface/print')->read();
        $ipAddresses = $client->query('/ip/address/print')->read();

        $ipMap = [];
        foreach ($ipAddresses as $ipItem) {
            $ifName = $ipItem['interface'] ?? null;
            if ($ifName && !empty($ipItem['address'])) {
                $parts = explode('/', $ipItem['address']);
                $ipMap[$ifName] = [
                    'ip' => $parts[0],
                    'subnet' => isset($parts[1]) ? '/' . $parts[1] : null,
                ];
            }
        }

        $count = 0;
        foreach ($interfaces as $iface) {
            $name = $iface['name'] ?? null;
            if (!$name) continue;

            $mac = $iface['mac-address'] ?? null;
            $type = $iface['type'] ?? 'Ethernet';
            $status = (($iface['running'] ?? 'false') === 'true' && ($iface['disabled'] ?? 'false') !== 'true') ? 'up' : 'down';
            
            $ipInfo = $ipMap[$name] ?? ['ip' => null, 'subnet' => null];

            \App\Models\DeviceInterface::updateOrCreate(
                [
                    'device_id' => $device->id,
                    'interface_name' => $name,
                ],
                [
                    'mac_address' => $mac,
                    'ip_address' => $ipInfo['ip'],
                    'subnet' => $ipInfo['subnet'],
                    'interface_type' => ucfirst($type),
                    'interface_status' => $status,
                    'description' => $iface['comment'] ?? null,
                ]
            );
            $count++;
        }

        return $count;
    }
}
