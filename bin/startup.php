<?php

declare(strict_types=1);

use Desertica\Billing\App;
use Desertica\Billing\Config\Settings;
use Desertica\Billing\Log\StderrLogger;

require __DIR__ . '/../vendor/autoload.php';

// Se ejecuta antes de aceptar tráfico: si la configuración o el certificado no sirven, el contenedor no arranca.
try {
    $settings = Settings::fromEnv(getenv());
    App::certificate($settings);
} catch (Throwable $e) {
    (new StderrLogger())->critical('startup_error', ['message' => $e->getMessage()]);
    exit(1);
}
