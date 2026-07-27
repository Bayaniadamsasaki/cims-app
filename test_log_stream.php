<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use RouterOS\Client;
use RouterOS\Query;

$host = env('MIKROTIK_HOST', '192.168.91.1');
$user = env('MIKROTIK_USER', 'cims-monitor');
$pass = env('MIKROTIK_PASSWORD', 'walnutcreek2018!');
$port = (int) env('MIKROTIK_PORT', 7111);

echo "Connecting to $host:$port as $user...\n";

try {
    $client = new Client([
        'host' => $host,
        'user' => $user,
        'pass' => $pass,
        'port' => $port,
        'timeout' => 3,
    ]);

    echo "Connected successfully!\n";

    echo "--- Test A: /system/logging/print ---\n";
    try {
        $q = new Query('/system/logging/print');
        $res = $client->query($q)->read();
        echo "Logging rules count: " . count($res) . "\n";
        print_r($res);
    } catch (\Throwable $e) {
        echo "Err A: " . $e->getMessage() . "\n";
    }

    echo "--- Test B: /log/print with .id where ---\n";
    try {
        $q = new Query('/log/print');
        // Limit query in RouterOS API
        $res = $client->query($q)->read();
        echo "Logs count: " . count($res) . "\n";
    } catch (\Throwable $e) {
        echo "Err B: " . $e->getMessage() . "\n";
    }

} catch (\Throwable $e) {
    echo "Caught Error: " . $e->getMessage() . "\n";
}
