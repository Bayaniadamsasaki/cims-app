<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RouterOS\Client;
use RouterOS\Query;

class MikrotikService
{
    protected ?Client $client = null;

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
            if ($targetHost && ($targetUser === null || $targetPass === null)) {
                $device = \App\Models\Device::where('ip_address', $targetHost)->first();
                if ($device) {
                    if ($targetUser === null && !empty($device->username)) {
                        $targetUser = $device->username;
                    }
                    if ($targetPass === null && !empty($device->password_encrypted)) {
                        try {
                            $targetPass = decrypt($device->password_encrypted);
                        } catch (\Throwable $e) {
                            $targetPass = $device->password_encrypted;
                        }
                    }
                }
            }

            // 3. Fallback to default .env config
            $targetUser = $targetUser ?? $config['user'];
            $targetPass = $targetPass ?? $config['password'];
            $targetPort = $targetPort ?? $config['port'];

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
            Log::warning("MikroTik connection failed: {$e->getMessage()}");

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch system resource metrics (CPU, RAM, storage, uptime, temperature).
     * Returns shape compatible with MonitoringService::querySnmp().
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
            Log::warning('MikroTik metrics fetch failed for ' . ($host ?? config('services.mikrotik.host')) . ": {$e->getMessage()}");
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
}
