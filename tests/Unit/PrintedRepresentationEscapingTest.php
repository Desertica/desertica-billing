<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Unit;

use Desertica\Billing\Config\Issuer;
use Desertica\Billing\Domain\RequestValidator;
use Desertica\Billing\Tests\Support\Payloads;
use Desertica\Billing\Xml\DocumentFactory;
use Greenter\Report\HtmlReport;
use Greenter\Report\Resolver\DefaultTemplateResolver;
use PHPUnit\Framework\TestCase;

/**
 * Los textos del cliente llegan al HTML que wkhtmltopdf convierte en PDF: si no se escaparan, un <iframe src="file://..."> en el
 * nombre del cliente podría leer archivos del servidor. Se comprueba el HTML sin necesidad del binario.
 */
final class PrintedRepresentationEscapingTest extends TestCase
{
    public function testUserTextIsHtmlEscapedInTheTemplate(): void
    {
        $evil = '<iframe src="file:///etc/passwd"></iframe><img src=x onerror=alert(1)>';
        $payload = Payloads::boleta();
        $payload['items'][0]['description'] = $evil;
        $payload['customer']['name'] = $evil;
        $payload['customer']['address'] = $evil;
        $payload['notes'] = $evil;

        $request = (new RequestValidator())->validate($payload, new \DateTimeImmutable('2026-10-04 12:00:00', new \DateTimeZone('America/Lima')));
        $document = (new DocumentFactory(new Issuer('20000000001', 'DESERTICA SAC', 'DESERTICA', 'AV 1', '150101', 'LIMA', 'LIMA', 'LIMA', '0000')))
            ->build($request)['document'];
        $report = new HtmlReport();
        $report->setTemplate((new DefaultTemplateResolver())->getTemplate($document));
        $html = (string) $report->render($document, [
            'system' => ['logo' => '', 'hash' => 'hash'],
            'user' => ['header' => '', 'extras' => [['name' => 'OBSERVACIONES', 'value' => $request->notes]], 'footer' => ''],
        ]);

        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;iframe', $html);
    }
}
