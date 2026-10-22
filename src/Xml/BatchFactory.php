<?php

declare(strict_types=1);

namespace Desertica\Billing\Xml;

use Desertica\Billing\Domain\Catalogs;
use Desertica\Billing\Domain\DocType;
use Desertica\Billing\Domain\TaxCalculator;
use Desertica\Billing\Persistence\DocumentRecord;
use Greenter\Model\Sale\Document;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Summary\SummaryDetail;
use Greenter\Model\Voided\Voided;
use Greenter\Model\Voided\VoidedDetail;

/**
 * Arma el resumen diario (RC) y la comunicación de baja (RA) con Greenter a partir de los comprobantes guardados.
 */
final class BatchFactory
{
    public function __construct(private readonly DocumentFactory $documents)
    {
    }

    /**
     * @param list<DocumentRecord> $records todos de la misma moneda
     */
    public function summary(
        array $records,
        string $currency,
        string $issueDate,
        string $sendDate,
        int $sequence,
        string $state = Catalogs::SUMMARY_ADD,
    ): Summary {
        $details = [];
        foreach ($records as $record) {
            $details[] = $this->detail($record, $state);
        }

        return (new Summary())
            ->setFecGeneracion($this->date($issueDate))
            ->setFecResumen($this->date($sendDate))
            ->setCorrelativo(str_pad((string) $sequence, 3, '0', STR_PAD_LEFT))
            ->setMoneda($currency)
            ->setCompany($this->documents->company())
            ->setDetails($details);
    }

    public function voided(DocumentRecord $record, string $voidDate, string $reason, int $sequence): Voided
    {
        $detail = (new VoidedDetail())
            ->setTipoDoc(DocType::from($record->docType)->sunatCode())
            ->setSerie($record->series)
            ->setCorrelativo((string) $record->number)
            ->setDesMotivoBaja($reason);

        return (new Voided())
            ->setCorrelativo(str_pad((string) $sequence, 5, '0', STR_PAD_LEFT))
            ->setFecGeneracion($this->date($record->issueDate))
            ->setFecComunicacion($this->date($voidDate))
            ->setCompany($this->documents->company())
            ->setDetails([$detail]);
    }

    private function detail(DocumentRecord $record, string $state): SummaryDetail
    {
        /** @var array{customer?: array{idDocType: string, idDocNumber: string}, affectedDocument?: array{docType: string, series: string, number: int}, igvRate: string} $request */
        $request = json_decode($record->requestJson, true, 512, JSON_THROW_ON_ERROR);
        $detail = (new SummaryDetail())
            ->setTipoDoc(DocType::from($record->docType)->sunatCode())
            ->setSerieNro($record->series . '-' . $record->number)
            ->setEstado($state)
            ->setClienteTipo(isset($request['customer']) ? Catalogs::ID_TYPES[$request['customer']['idDocType']] : Catalogs::ID_TYPE_NONE)
            ->setClienteNro($request['customer']['idDocNumber'] ?? Catalogs::ANONYMOUS_NUMBER)
            ->setTotal((float) TaxCalculator::money($record->totalCents))
            ->setMtoOperGravadas((float) TaxCalculator::money($record->taxableCents))
            ->setMtoIGV((float) TaxCalculator::money($record->igvCents));

        if (isset($request['affectedDocument'])) {
            $affected = $request['affectedDocument'];
            $detail->setDocReferencia(
                (new Document())
                    ->setTipoDoc(DocType::from($affected['docType'])->sunatCode())
                    ->setNroDoc($affected['series'] . '-' . $affected['number']),
            );
        }

        return $detail;
    }

    private function date(string $ymd): \DateTime
    {
        return new \DateTime($ymd . ' 00:00:00', new \DateTimeZone('America/Lima'));
    }
}
