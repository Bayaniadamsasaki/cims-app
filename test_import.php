<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$path = 'C:\\laragon\\www\\cims-app\\Docs\\audit_example.xlsx';
$can = App\Imports\SingleDeviceAuditImport::canHandle($path);
echo "canHandle: ".($can?'YES':'NO').PHP_EOL;

if ($can) {
    $imp = new App\Imports\SingleDeviceAuditImport();
    $device = $imp->import($path);
    echo "Device: ".($device ? $device->name : 'null').PHP_EOL;
}
