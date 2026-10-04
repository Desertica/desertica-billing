<?php

declare(strict_types=1);

namespace Desertica\Billing;

use Desertica\Billing\Config\Environment;
use Desertica\Billing\Config\Settings;
use Desertica\Billing\Http\Controller\BatchController;
use Desertica\Billing\Http\Controller\DocumentController;
use Desertica\Billing\Http\Controller\HealthController;
use Desertica\Billing\Http\Error\ErrorHandler;
use Desertica\Billing\Http\Middleware\BearerAuthMiddleware;
use Desertica\Billing\Log\StderrLogger;
use Desertica\Billing\Persistence\Database;
use Desertica\Billing\Persistence\DocumentRepository;
use Desertica\Billing\Persistence\TicketRepository;
use Desertica\Billing\Security\CertificateProvider;
use Desertica\Billing\Service\BatchService;
use Desertica\Billing\Service\Clock;
use Desertica\Billing\Service\EmissionService;
use Desertica\Billing\Service\PdfRenderer;
use Desertica\Billing\Service\SystemClock;
use Desertica\Billing\Storage\FileStorage;
use Desertica\Billing\Sunat\FakeSunatGateway;
use Desertica\Billing\Sunat\GreenterSunatGateway;
use Desertica\Billing\Sunat\SunatGateway;
use Desertica\Billing\Xml\BatchFactory;
use Desertica\Billing\Xml\DocumentFactory;
use Desertica\Billing\Xml\XmlSigner;
use Greenter\Ws\Services\SunatEndpoints;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;

final class App
{
    /**
     * @return SlimApp<ContainerInterface|null>
     */
    public static function create(
        Settings $settings,
        ?LoggerInterface $logger = null,
        ?SunatGateway $gateway = null,
        ?Clock $clock = null,
        ?\PDO $pdo = null,
    ): SlimApp {
        $logger ??= new StderrLogger();
        $clock ??= new SystemClock();
        $pdo ??= Database::connect($settings->dbDsn);
        Database::prepare($pdo);

        $certificate = self::certificate($settings);
        $gateway ??= self::gateway($settings, $certificate);
        $documents = new DocumentRepository($pdo);
        $storage = new FileStorage($settings->storageDir);
        $factory = new DocumentFactory($settings->issuer, $settings->billing);
        $signer = new XmlSigner($certificate);
        $pdf = new PdfRenderer($settings->wkhtmltopdfBinary, $settings->logoPath, $logger);

        $emission = new EmissionService(
            $documents,
            $storage,
            $gateway,
            $factory,
            $signer,
            $pdf,
            $clock,
            $settings->boletaSubmission,
            $logger,
            $settings->billing->anonymousBoletaMaxCents,
            $settings->alreadyRegisteredCodes,
        );
        $batches = new BatchService(
            $documents,
            new TicketRepository($pdo),
            $storage,
            $gateway,
            new BatchFactory($factory),
            $signer,
            $clock,
            $logger,
        );
        $documentController = new DocumentController($emission);
        $batchController = new BatchController($batches);

        $app = AppFactory::create();
        // Orden de ejecución (el último que se añade corre primero): errores → auth → rutas.
        $app->addRoutingMiddleware();
        $app->add(new BearerAuthMiddleware($settings->serviceToken));
        $errors = $app->addErrorMiddleware(false, false, false, $logger);
        $errors->setDefaultErrorHandler(new ErrorHandler($app->getResponseFactory(), $logger));

        $app->get('/health', new HealthController($settings->environment));
        $app->post('/v1/documents', [$documentController, 'emit']);
        $app->get('/v1/documents/{externalId}', [$documentController, 'get']);
        $app->get('/v1/documents/{externalId}/files/{kind}', [$documentController, 'file']);
        $app->post('/v1/documents/{externalId}/void', [$batchController, 'void']);
        $app->post('/v1/summaries/daily', [$batchController, 'dailySummary']);
        $app->get('/v1/tickets/{ticket}', [$batchController, 'ticket']);

        return $app;
    }

    private static function certificate(Settings $settings): string
    {
        if ($settings->certPath !== '') {
            return CertificateProvider::fromFile($settings->certPath, $settings->certPassword);
        }

        // Sin certificado configurado solo se llega con gateway fake (Settings lo exige para greenter): certificado efímero de prueba.
        return CertificateProvider::generateTestCertificate();
    }

    private static function gateway(Settings $settings, string $certificate): SunatGateway
    {
        if ($settings->gateway === 'fake') {
            return new FakeSunatGateway();
        }

        return GreenterSunatGateway::connect(
            $certificate,
            $settings->issuer->ruc,
            $settings->solUser,
            $settings->solPassword->reveal(),
            $settings->environment === Environment::Production ? SunatEndpoints::FE_PRODUCCION : SunatEndpoints::FE_BETA,
            $settings->cdrConsultUrl,
            $settings->sunatCaFile,
        );
    }
}
