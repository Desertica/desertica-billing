<?php

declare(strict_types=1);

namespace Desertica\Billing\Persistence;

final class TicketRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function find(string $ticket): ?TicketRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tickets WHERE ticket = :t');
        $stmt->execute(['t' => $ticket]);
        $row = $stmt->fetch();

        return is_array($row) ? TicketRecord::fromRow($row) : null;
    }

    /**
     * Último resumen enviado para una fecha de emisión y moneda (para responder de nuevo si el API repite la llamada).
     */
    public function latestSummary(string $refDate): ?TicketRecord
    {
        $stmt = $this->pdo->prepare("SELECT * FROM tickets WHERE kind = 'SUMMARY' AND ref_date = :d ORDER BY created_at DESC, ticket DESC LIMIT 1");
        $stmt->execute(['d' => $refDate]);
        $row = $stmt->fetch();

        return is_array($row) ? TicketRecord::fromRow($row) : null;
    }

    /**
     * @param list<array{externalId: string, action: string}> $documents
     */
    public function create(TicketRecord $t, array $documents, string $now): void
    {
        $this->pdo->prepare(<<<'SQL'
INSERT INTO tickets (ticket, kind, file_name, ref_date, currency, status, created_at, updated_at)
VALUES (:ticket, :kind, :file_name, :ref_date, :currency, :status, :now, :now)
SQL)->execute([
            'ticket' => $t->ticket,
            'kind' => $t->kind,
            'file_name' => $t->fileName,
            'ref_date' => $t->refDate,
            'currency' => $t->currency,
            'status' => $t->status,
            'now' => $now,
        ]);
        $link = $this->pdo->prepare('INSERT INTO ticket_documents (ticket, external_id, action) VALUES (:t, :e, :a)');
        foreach ($documents as $doc) {
            $link->execute(['t' => $t->ticket, 'e' => $doc['externalId'], 'a' => $doc['action']]);
        }
    }

    public function updateStatus(string $ticket, string $status, ?string $code, ?string $message, string $now): void
    {
        $this->pdo->prepare('UPDATE tickets SET status = :s, sunat_code = :c, sunat_message = :m, updated_at = :now WHERE ticket = :t')
            ->execute(['s' => $status, 'c' => $code, 'm' => $message, 'now' => $now, 't' => $ticket]);
    }

    /**
     * @return list<array{externalId: string, action: string}>
     */
    public function documents(string $ticket): array
    {
        $stmt = $this->pdo->prepare('SELECT external_id, action FROM ticket_documents WHERE ticket = :t ORDER BY external_id');
        $stmt->execute(['t' => $ticket]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = ['externalId' => (string) $row['external_id'], 'action' => (string) $row['action']];
        }

        return $out;
    }

    /**
     * Siguiente correlativo del día para los resúmenes (RC) y las comunicaciones de baja (RA).
     */
    public function nextSequence(string $kind, string $day): int
    {
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare('UPDATE batch_sequences SET last_number = last_number + 1 WHERE kind = :k AND day = :d');
            $update->execute(['k' => $kind, 'd' => $day]);
            if ($update->rowCount() === 0) {
                $this->pdo->prepare('INSERT INTO batch_sequences (kind, day, last_number) VALUES (:k, :d, 1)')
                    ->execute(['k' => $kind, 'd' => $day]);
            }
            $select = $this->pdo->prepare('SELECT last_number FROM batch_sequences WHERE kind = :k AND day = :d');
            $select->execute(['k' => $kind, 'd' => $day]);
            $number = (int) $select->fetchColumn();
            $this->pdo->commit();

            return $number;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
