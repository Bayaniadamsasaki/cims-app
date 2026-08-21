<?php
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$exitCode = $kernel->handle(
    Illuminate\Console\Input\ArgvInput::createFromArray([]),
    new Illuminate\Console\Output\ConsoleOutput()
);
echo "Done\n";