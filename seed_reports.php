<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$userReport = new App\Models\Report();
$userReport->type = 'users';
$userReport->description = 'Reporte de usuario con mal comportamiento falso para tener métricas';
$userReport->status = 'pending';
$userReport->user_id = 1;
$userReport->save();

$propReport = new App\Models\Report();
$propReport->type = 'properties';
$propReport->description = 'Reporte de propiedad falso para tener métricas';
$propReport->status = 'pending';
$propReport->user_id = 1;
$propReport->save();

echo "Reports generated.\n";
