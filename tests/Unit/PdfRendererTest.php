<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Unit;

use Desertica\Billing\Config\Issuer;
use Desertica\Billing\Domain\RequestValidator;
use Desertica\Billing\Service\PdfRenderer;
use Desertica\Billing\Tests\Support\Payloads;
use Desertica\Billing\Xml\DocumentFactory;
use Greenter\Model\DocumentInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PdfRendererTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        array_map('unlink', $this->files);
    }

    private function tmp(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        self::assertNotFalse($path);

        return $this->files[] = $path;
    }

    private function document(): DocumentInterface
    {
        $request = (new RequestValidator())->validate(Payloads::boleta(), new \DateTimeImmutable('2026-10-04 12:00:00', new \DateTimeZone('America/Lima')));

        return (new DocumentFactory(new Issuer('20000000001', 'DESERTICA SAC', 'DESERTICA', 'AV 1', '150101', 'LIMA', 'LIMA', 'LIMA', '0000')))
            ->build($request)['document'];
    }

    public function testWkhtmltopdfDoesNotInheritTheEnvironment(): void
    {
        putenv('BILLING_SERVICE_TOKEN=token-que-no-debe-heredarse');
        $dump = $this->tmp();
        $script = $this->tmp();
        file_put_contents($script, "#!/bin/sh\nenv > '$dump'\nexit 1\n");
        chmod($script, 0700);

        try {
            (new PdfRenderer($script, '', new NullLogger()))->render($this->document(), 'hash');
        } finally {
            putenv('BILLING_SERVICE_TOKEN');
        }

        $env = (string) file_get_contents($dump);
        self::assertStringContainsString('PATH=', $env);
        self::assertStringNotContainsString('BILLING_SERVICE_TOKEN', $env);
    }

    public function testPdfWithLogoIsStillRenderedWithoutLocalFileAccess(): void
    {
        if (trim((string) shell_exec('command -v wkhtmltopdf')) === '') {
            self::markTestSkipped('wkhtmltopdf no está disponible.');
        }
        $logo = $this->tmp();
        file_put_contents($logo, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        $pdf = (new PdfRenderer('wkhtmltopdf', $logo, new NullLogger()))->render($this->document(), 'hash');

        self::assertNotNull($pdf);
        self::assertStringStartsWith('%PDF', $pdf);
    }
}
