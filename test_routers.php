<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$req = new \Illuminate\Http\Request();
$ctrl = app(\App\Http\Controllers\Web\MikrotikWebController::class);
$res = $ctrl->index($req);

$props = $res->toResponse($req)->getOriginalContent();
var_dump($props['page']['props']['availableRouters']);
var_dump($props['page']['props']['selectedHost']);
var_dump($props['page']['props']['connection']);
