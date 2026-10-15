<?php

declare(strict_types=1);

namespace Desertica\Billing\Xml;

use Desertica\Billing\Config\BillingOptions;
use Desertica\Billing\Config\Issuer;
use Desertica\Billing\Domain\AmountInWords;
use Desertica\Billing\Domain\Catalogs;
use Desertica\Billing\Domain\DocType;
use Desertica\Billing\Domain\EmitRequest;
use Desertica\Billing\Domain\TaxCalculator;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\Note;
use Greenter\Model\Sale\SaleDetail;

/**
 * Convierte una `EmitRequest` validada en el modelo de Greenter (boleta, factura, nota de crédito o de débito).
 * Los montos llegan en céntimos con IGV incluido y se reparten con `TaxCalculator`.
 */
final class DocumentFactory
{
    public function __construct(private readonly Issuer $issuer, private readonly BillingOptions $options = new BillingOptions())
    {
    }

    /**
     * @return array{document: Invoice|Note, taxableCents: int, igvCents: int, totalCents: int}
     */
    public function build(EmitRequest $request): array
    {
        $rate = TaxCalculator::parseRate($request->igvRate);
        $totals = TaxCalculator::totals(
            array_map(static fn (array $i) => ['quantity' => $i['quantity'], 'totalCents' => $i['totalCents']], $request->items),
            $rate,
        );

        $document = $request->docType->isNote() ? new Note() : new Invoice();
        $document
            ->setUblVersion('2.1')
            ->setTipoDoc($request->docType->sunatCode())
            ->setSerie($request->series)
            ->setCorrelativo((string) $request->number)
            ->setFechaEmision(new \DateTime($request->issueDate . ' 00:00:00', new \DateTimeZone('America/Lima')))
            ->setTipoMoneda($request->currency)
            ->setCompany($this->company())
            ->setClient($this->client($request))
            ->setMtoOperGravadas((float) TaxCalculator::money($totals['taxableCents']))
            ->setMtoIGV((float) TaxCalculator::money($totals['igvCents']))
            ->setTotalImpuestos((float) TaxCalculator::money($totals['igvCents']))
            ->setMtoImpVenta((float) TaxCalculator::money($totals['totalCents']));

        if ($document instanceof Invoice) {
            $document
                ->setTipoOperacion(Catalogs::OPERATION_DOMESTIC_SALE)
                ->setFormaPago(new FormaPagoContado())
                ->setValorVenta((float) TaxCalculator::money($totals['taxableCents']))
                ->setSubTotal((float) TaxCalculator::money($totals['totalCents']));
        } else {
            $this->configureNote($document, $request);
        }

        $details = [];
        foreach ($request->items as $i => $item) {
            $line = $totals['lines'][$i];
            $details[] = (new SaleDetail())
                ->setCodProducto(sprintf('T%03d', $i + 1))
                ->setUnidad($this->options->unitCode)
                ->setCantidad($item['quantity'])
                ->setDescripcion($item['description'])
                ->setMtoBaseIgv((float) TaxCalculator::money($line['taxableCents']))
                ->setPorcentajeIgv((float) $request->igvRate * 100)
                ->setIgv((float) TaxCalculator::money($line['igvCents']))
                ->setTipAfeIgv(Catalogs::IGV_AFFECTATION_TAXED)
                ->setTotalImpuestos((float) TaxCalculator::money($line['igvCents']))
                ->setMtoValorVenta((float) TaxCalculator::money($line['taxableCents']))
                ->setMtoValorUnitario((float) TaxCalculator::unitValue($line['taxableCents'], $item['quantity']))
                ->setMtoPrecioUnitario((float) TaxCalculator::money($item['unitPriceCents']));
        }

        $legends = [
            (new Legend())
                ->setCode(Catalogs::LEGEND_AMOUNT_IN_WORDS)
                ->setValue(AmountInWords::format($totals['totalCents'], $request->currency)),
        ];
        if ($this->options->travelAgencyLegend && $request->isFor(DocType::Boleta, DocType::Factura)) {
            $legends[] = (new Legend())->setCode(Catalogs::LEGEND_TRAVEL_AGENCY)->setValue(Catalogs::LEGEND_TRAVEL_AGENCY_TEXT);
        }
        $document->setDetails($details)->setLegends($legends);

        return [
            'document' => $document,
            'taxableCents' => $totals['taxableCents'],
            'igvCents' => $totals['igvCents'],
            'totalCents' => $totals['totalCents'],
        ];
    }

    public function issuerRuc(): string
    {
        return $this->issuer->ruc;
    }

    public function company(): Company
    {
        $address = (new Address())
            ->setUbigueo($this->issuer->ubigeo)
            ->setDepartamento($this->issuer->department)
            ->setProvincia($this->issuer->province)
            ->setDistrito($this->issuer->district)
            ->setUrbanizacion('-')
            ->setDireccion($this->issuer->address)
            ->setCodLocal($this->issuer->localCode);

        return (new Company())
            ->setRuc($this->issuer->ruc)
            ->setRazonSocial($this->issuer->legalName)
            ->setNombreComercial($this->issuer->tradeName)
            ->setAddress($address);
    }

    private function client(EmitRequest $request): Client
    {
        $customer = $request->customer;
        if ($customer === null) {
            // Boleta sin receptor (solo si BILLING_ANONYMOUS_BOLETA_MAX_CENTS lo permite y el monto está por debajo).
            return (new Client())
                ->setTipoDoc(Catalogs::ID_TYPE_NONE)
                ->setNumDoc(Catalogs::ANONYMOUS_NUMBER)
                ->setRznSocial(Catalogs::ANONYMOUS_NAME);
        }
        $client = (new Client())
            ->setTipoDoc(Catalogs::ID_TYPES[$customer['idDocType']])
            ->setNumDoc($customer['idDocNumber'])
            ->setRznSocial($customer['name']);
        if (isset($customer['address'])) {
            $client->setAddress((new Address())->setDireccion($customer['address']));
        }

        return $client;
    }

    private function configureNote(Note $note, EmitRequest $request): void
    {
        $affected = $request->affectedDocument;
        $reasonCode = $request->reasonCode;
        if ($affected === null || $reasonCode === null) {
            throw new \LogicException('Una nota requiere documento afectado y motivo.');
        }
        $reasons = Catalogs::reasonsFor($request->docType);
        $note
            ->setTipDocAfectado(DocType::from($affected['docType'])->sunatCode())
            ->setNumDocfectado(sprintf('%s-%d', $affected['series'], $affected['number']))
            ->setCodMotivo($reasonCode)
            ->setDesMotivo($request->reason ?? $reasons[$reasonCode]);
    }
}
