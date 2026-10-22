<?php

declare(strict_types=1);

namespace Desertica\Billing\Storage;

/**
 * Almacenamiento temporal de XML firmado, CDR y PDF en BILLING_STORAGE_DIR.
 * La conservación con retención legal es del API, que los descarga.
 */
final class FileStorage
{
    public const XML = 'xml';
    public const CDR = 'cdr';
    public const PDF = 'pdf';

    private const FILES = [self::XML => 'document.xml', self::CDR => 'cdr.zip', self::PDF => 'document.pdf'];

    public function __construct(private readonly string $baseDir)
    {
    }

    public function write(string $externalId, string $kind, string $content): void
    {
        $path = $this->path($externalId, $kind);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear el directorio de almacenamiento.');
        }
        // Escritura atómica: nunca se ve un archivo a medias.
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $content) === false || !chmod($tmp, 0600) || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('No se pudo escribir el archivo.');
        }
    }

    public function read(string $externalId, string $kind): ?string
    {
        $path = $this->path($externalId, $kind);
        if (!is_file($path)) {
            return null;
        }
        $content = file_get_contents($path);

        return $content === false ? null : $content;
    }

    public function writeTicketXml(string $ticket, string $xml): void
    {
        $this->write('tickets/' . self::safe($ticket), self::XML, $xml);
    }

    private function path(string $externalId, string $kind): string
    {
        if (!isset(self::FILES[$kind])) {
            throw new \InvalidArgumentException('Tipo de archivo desconocido.');
        }
        if (!preg_match('#^(tickets/)?[A-Za-z0-9._-]+$#', $externalId)) {
            throw new \InvalidArgumentException('Identificador de archivo inválido.');
        }

        return rtrim($this->baseDir, '/') . '/' . $externalId . '/' . self::FILES[$kind];
    }

    private static function safe(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $value) ?? '_';
    }
}
