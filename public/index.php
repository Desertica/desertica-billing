<?php

declare(strict_types=1);

use Desertica\Billing\App;
use Desertica\Billing\Config\ConfigException;
use Desertica\Billing\Config\Settings;

require __DIR__ . '/../vendor/autoload.php';

try {
    $settings = Settings::fromEnv(getenv());
} catch (ConfigException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    error_log('configuration error: ' . $e->getMessage());
    echo json_encode(['code' => 'CONFIG_ERROR', 'message' => 'Configuración inválida.', 'retryable' => false]);
    exit(1);
}

App::create($settings)->run();
