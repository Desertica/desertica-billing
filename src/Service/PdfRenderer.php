<?php

declare(strict_types=1);

namespace Desertica\Billing\Service;

use Greenter\Model\DocumentInterface;
use Greenter\Report\HtmlReport;
use Greenter\Report\PdfReport;
use Greenter\Report\Resolver\DefaultTemplateResolver;
use Psr\Log\LoggerInterface;

/**
 * Representación impresa (PDF) con `greenter/report` + wkhtmltopdf. Si el binario falla devuelve null:
 * el PDF se puede regenerar luego y nunca debe tumbar una emisión.
 */
final class PdfRenderer
{
    public function __construct(
        private readonly string $binary,
        private readonly string $logoPath,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function render(DocumentInterface $document, string $hash, ?string $notes = null): ?string
    {
        $logo = $this->logoPath !== '' && is_file($this->logoPath) ? (string) file_get_contents($this->logoPath) : '';
        $extras = $notes !== null ? [['name' => 'OBSERVACIONES', 'value' => $notes]] : [];

        try {
            $html = new HtmlReport();
            $html->setTemplate((new DefaultTemplateResolver())->getTemplate($document));
            $report = new PdfReport($html);
            $report->setBinPath($this->binary);
            $report->setOptions([
                'no-outline',
                'page-width' => '21cm',
                'page-height' => '29.7cm',
                'disable-javascript',
                // El HTML lleva el logo en línea (data URI): wkhtmltopdf no necesita leer archivos locales (el HTML de entrada sí se carga).
                'disable-local-file-access',
            ]);
            // El proceso no hereda el entorno: ahí están el token de servicio, la clave SOL y la del certificado.
            $exporter = $report->getExporter();
            if ($exporter !== null) {
                $exporter->commandOptions = ['procEnv' => ['PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin')]];
            }
            $pdf = $report->render($document, [
                'system' => ['logo' => $logo, 'hash' => $hash],
                'user' => ['header' => '', 'extras' => $extras, 'footer' => ''],
            ]);
            if ($pdf === null || $pdf === '') {
                $this->logger->warning('pdf_render_failed', ['error' => (string) $report->getExporter()?->getError()]);

                return null;
            }

            return $pdf;
        } catch (\Throwable $e) {
            $this->logger->warning('pdf_render_failed', ['class' => $e::class, 'message' => $e->getMessage()]);

            return null;
        }
    }
}
