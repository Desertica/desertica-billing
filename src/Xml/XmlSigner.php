<?php

declare(strict_types=1);

namespace Desertica\Billing\Xml;

use Greenter\Model\DocumentInterface;
use Greenter\See;

/**
 * Genera el XML UBL de un modelo de Greenter y lo firma con el certificado. No habla con SUNAT.
 */
final class XmlSigner
{
    private readonly See $see;

    public function __construct(string $certificatePem)
    {
        $this->see = new See();
        $this->see->setCertificate($certificatePem);
    }

    public function sign(DocumentInterface $document): SignedXml
    {
        $xml = $this->see->getXmlSigned($document);
        if ($xml === null || $xml === '') {
            throw new \RuntimeException('Greenter no pudo generar o firmar el XML.');
        }
        $name = (string) $document->getName();
        if ($name === '') {
            throw new \RuntimeException('El documento no tiene nombre de archivo.');
        }

        return new SignedXml($xml, $name, self::digestValue($xml));
    }

    /**
     * Valor `ds:DigestValue` de la firma: es el «hash» que se imprime en el PDF y que guarda el API.
     */
    public static function digestValue(string $xml): string
    {
        $doc = new \DOMDocument();
        if (!@$doc->loadXML($xml)) {
            throw new \RuntimeException('XML firmado inválido.');
        }
        $node = $doc->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'DigestValue')->item(0);
        if ($node === null) {
            throw new \RuntimeException('El XML no contiene firma.');
        }

        return trim($node->textContent);
    }
}
