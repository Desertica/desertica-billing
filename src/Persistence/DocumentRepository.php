<?php

declare(strict_types=1);

namespace Desertica\Billing\Persistence;

final class DocumentRepository
{
    private const COLUMNS = [
        'status', 'hash', 'sunat_code', 'sunat_message', 'ticket', 'void_ticket', 'void_reason',
        'has_xml', 'has_cdr', 'has_pdf', 'send_attempted',
    ];

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function find(string $externalId): ?DocumentRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM documents WHERE external_id = :id');
        $stmt->execute(['id' => $externalId]);
        $row = $stmt->fetch();

        return is_array($row) ? DocumentRecord::fromRow($row) : null;
    }

    public function findBySeriesNumber(string $series, int $number): ?DocumentRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM documents WHERE series = :s AND number = :n');
        $stmt->execute(['s' => $series, 'n' => $number]);
        $row = $stmt->fetch();

        return is_array($row) ? DocumentRecord::fromRow($row) : null;
    }

    /**
     * Inserta el comprobante. Lanza `\PDOException` si `externalId` o (serie, número) ya existen.
     */
    public function insert(DocumentRecord $r, string $now): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO documents (external_id, request_hash, request_json, doc_type, series, number, issue_date, currency, submission,
    status, taxable_cents, igv_cents, total_cents, file_name, hash, has_xml, has_cdr, has_pdf, created_at, updated_at)
VALUES (:external_id, :request_hash, :request_json, :doc_type, :series, :number, :issue_date, :currency, :submission,
    :status, :taxable, :igv, :total, :file_name, :hash, :has_xml, 0, 0, :now, :now)
SQL);
        $stmt->execute([
            'external_id' => $r->externalId,
            'request_hash' => $r->requestHash,
            'request_json' => $r->requestJson,
            'doc_type' => $r->docType,
            'series' => $r->series,
            'number' => $r->number,
            'issue_date' => $r->issueDate,
            'currency' => $r->currency,
            'submission' => $r->submission,
            'status' => $r->status,
            'taxable' => $r->taxableCents,
            'igv' => $r->igvCents,
            'total' => $r->totalCents,
            'file_name' => $r->fileName,
            'hash' => $r->hash,
            'has_xml' => $r->hasXml ? 1 : 0,
            'now' => $now,
        ]);
    }

    /**
     * @param array<string, string|int|bool|null> $changes columnas de self::COLUMNS
     */
    public function update(string $externalId, array $changes, string $now): void
    {
        $sets = [];
        $params = ['id' => $externalId, 'now' => $now];
        foreach ($changes as $column => $value) {
            if (!in_array($column, self::COLUMNS, true)) {
                throw new \InvalidArgumentException('Columna no actualizable: ' . $column);
            }
            $sets[] = "$column = :$column";
            $params[$column] = is_bool($value) ? (int) $value : $value;
        }
        $stmt = $this->pdo->prepare('UPDATE documents SET ' . implode(', ', $sets) . ', updated_at = :now WHERE external_id = :id');
        $stmt->execute($params);
    }

    /**
     * Comprobantes de una fecha que esperan el resumen diario.
     *
     * @return list<DocumentRecord>
     */
    public function pendingForSummary(string $issueDate): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM documents WHERE issue_date = :d AND submission = 'DAILY_SUMMARY' AND status = 'ISSUED' "
            . 'AND ticket IS NULL AND doc_type IN (\'BOLETA\', \'NOTA_CREDITO\', \'NOTA_DEBITO\') ORDER BY currency, series, number'
        );
        $stmt->execute(['d' => $issueDate]);

        return array_values(array_map(static fn (array $row) => DocumentRecord::fromRow($row), $stmt->fetchAll()));
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
