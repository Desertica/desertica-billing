<?php

declare(strict_types=1);

use Desertica\Billing\App;
use Desertica\Billing\Config\ConfigException;
use Desertica\Billing\Config\Settings;
use Desertica\Billing\Log\PhpErrorLogging;
use Desertica\Billing\Log\StderrLogger;

require __DIR__ . '/../vendor/autoload.php';

try {
    $settings = Settings::fromEnv(getenv());
} catch (ConfigException $e) {
    // Los mensajes de configuración nombran variables, nunca sus valores.
    (new StderrLogger())->critical('configuration_error', ['message' => $e->getMessage()]);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['code' => 'INTERNAL_ERROR', 'message' => 'Configuración inválida.', 'retryable' => false]);
    exit(1);
}

// A partir de aquí, cualquier secreto de la configuración que aparezca en un mensaje se tacha.
$logger = new StderrLogger($settings->secretValues());
PhpErrorLogging::register($logger);

App::create($settings, $logger)->run();
