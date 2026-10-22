<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Unit;

use Desertica\Billing\Config\Secret;
use Desertica\Billing\Security\CertificateProvider;
use PHPUnit\Framework\TestCase;

final class CertificateProviderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        array_map('unlink', $this->files);
    }

    private function file(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cert');
        self::assertNotFalse($path);
        file_put_contents($path, $content);

        return $this->files[] = $path;
    }

    private function pfx(string $pem, string $password): string
    {
        $cert = openssl_x509_read($pem);
        $key = openssl_pkey_get_private($pem);
        self::assertNotFalse($cert);
        self::assertNotFalse($key);
        openssl_pkcs12_export($cert, $out, $key, $password);

        return $this->file($out);
    }

    public function testReadsPem(): void
    {
        $pem = CertificateProvider::generateTestCertificate();

        self::assertSame($pem, CertificateProvider::fromFile($this->file($pem), new Secret('')));
    }

    public function testReadsPfx(): void
    {
        $path = $this->pfx(CertificateProvider::generateTestCertificate(), 'secreto');

        self::assertStringContainsString('BEGIN CERTIFICATE', CertificateProvider::fromFile($path, new Secret('secreto')));
    }

    public function testRejectsWrongPfxPassword(): void
    {
        $path = $this->pfx(CertificateProvider::generateTestCertificate(), 'secreto');

        $this->expectExceptionMessage('clave incorrecta');
        CertificateProvider::fromFile($path, new Secret('otra'));
    }

    public function testRejectsExpiredCertificate(): void
    {
        $path = $this->file(CertificateProvider::generateTestCertificate());

        $this->expectExceptionMessage('vencido');
        CertificateProvider::fromFile($path, new Secret(''), null, time() + 86400 * 365);
    }

    public function testRejectsKeyThatDoesNotMatchTheCertificate(): void
    {
        $a = CertificateProvider::generateTestCertificate();
        $b = CertificateProvider::generateTestCertificate();
        preg_match('/-----BEGIN CERTIFICATE-----.*-----END CERTIFICATE-----/s', $a, $cert);
        $key = substr($b, 0, (int) strpos($b, '-----BEGIN CERTIFICATE-----'));

        $this->expectExceptionMessage('no corresponde');
        CertificateProvider::fromFile($this->file($key . $cert[0]), new Secret(''));
    }

    public function testChecksTheCertificateRucAgainstTheIssuer(): void
    {
        $path = $this->file(CertificateProvider::generateTestCertificate('DESERTICA RUC 20123456789'));

        self::assertNotSame('', CertificateProvider::fromFile($path, new Secret(''), '20123456789'));
        $this->expectExceptionMessage('RUC');
        CertificateProvider::fromFile($path, new Secret(''), '20987654321');
    }

    public function testCertificateWithoutRucIsAccepted(): void
    {
        $path = $this->file(CertificateProvider::generateTestCertificate());

        self::assertNotSame('', CertificateProvider::fromFile($path, new Secret(''), '20123456789'));
    }
}
