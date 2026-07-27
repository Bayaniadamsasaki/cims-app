<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

/** @var \App\Services\MikrotikService $mikrotik */
$mikrotik = app(\App\Services\MikrotikService::class);

echo "Testing MikroTik Connection...\n";
$conn = $mikrotik->testConnection();
var_dump($conn);

echo "\nFetching Logs...\n";
$logs = $mikrotik->getLogs(null, 20);
echo "Total logs returned: " . count($logs) . "\n";
print_r(array_slice($logs, 0, 10));
