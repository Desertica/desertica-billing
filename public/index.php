<?php

declare(strict_types=1);

use Desertica\Billing\App;
use Desertica\Billing\Config\ConfigException;
use Desertica\Billing\Config\Settings;
use Desertica\Billing\Log\PhpErrorLogging;
use Desertica\Billing\Log\StderrLogger;

require __DIR__ . '/../vendor/autoload.php';

$logger = new StderrLogger();
PhpErrorLogging::register($logger);

try {
    $settings = Settings::fromEnv(getenv());
} catch (ConfigException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    $logger->critical('configuration_error', ['message' => $e->getMessage()]);
    echo json_encode(['code' => 'INTERNAL_ERROR', 'message' => 'Configuración inválida.', 'retryable' => false]);
    exit(1);
}

App::create($settings, $logger)->run();
