<?php

declare(strict_types=1);

namespace Desertica\Billing;

use Desertica\Billing\Config\Settings;
use Desertica\Billing\Http\Controller\HealthController;
use Desertica\Billing\Http\Error\ErrorHandler;
use Desertica\Billing\Http\Middleware\BearerAuthMiddleware;
use Desertica\Billing\Log\StderrLogger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;

final class App
{
    /**
     * @return SlimApp<ContainerInterface|null>
     */
    public static function create(Settings $settings, ?LoggerInterface $logger = null): SlimApp
    {
        $logger ??= new StderrLogger();
        $app = AppFactory::create();

        // Orden de ejecución (el último que se añade corre primero): errores → auth → rutas.
        $app->addRoutingMiddleware();
        $app->add(new BearerAuthMiddleware($settings->serviceToken));
        $errors = $app->addErrorMiddleware(false, false, false, $logger);
        $errors->setDefaultErrorHandler(new ErrorHandler($app->getResponseFactory(), $logger));

        $app->get('/health', new HealthController($settings->environment));

        return $app;
    }
}
