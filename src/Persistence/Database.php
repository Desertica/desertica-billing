<?php

declare(strict_types=1);

namespace Desertica\Billing\Persistence;

/**
 * Conexión PDO y esquema. SQLite por defecto; el DSN es configurable (BILLING_DB_DSN).
 * El esquema usa solo tipos portables (TEXT, INTEGER).
 */
final class Database
{
    public static function connect(string $dsn): \PDO
    {
        if (str_starts_with($dsn, 'sqlite:') && !str_contains($dsn, ':memory:')) {
            $dir = dirname(substr($dsn, 7));
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('No se pudo crear el directorio de la base de datos.');
            }
        }
        return new \PDO($dsn);
    }

    /**
     * Configura una conexión (propia o inyectada) y crea el esquema si falta.
     */
    public static function prepare(\PDO $pdo): void
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $list = $pdo->query('PRAGMA database_list');
            $row = $list === false ? false : $list->fetch();
            if (is_array($row) && ($row['file'] ?? '') !== '') {
                $pdo->exec('PRAGMA journal_mode = WAL');
            }
        }
        self::migrate($pdo);
    }

    public static function migrate(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS documents (
    external_id TEXT PRIMARY KEY,
    request_hash TEXT NOT NULL,
    request_json TEXT NOT NULL,
    doc_type TEXT NOT NULL,
    series TEXT NOT NULL,
    number INTEGER NOT NULL,
    issue_date TEXT NOT NULL,
    currency TEXT NOT NULL,
    submission TEXT NOT NULL,
    status TEXT NOT NULL,
    taxable_cents INTEGER NOT NULL,
    igv_cents INTEGER NOT NULL,
    total_cents INTEGER NOT NULL,
    file_name TEXT NOT NULL,
    hash TEXT,
    sunat_code TEXT,
    sunat_message TEXT,
    ticket TEXT,
    void_ticket TEXT,
    void_reason TEXT,
    has_xml INTEGER NOT NULL DEFAULT 0,
    has_cdr INTEGER NOT NULL DEFAULT 0,
    has_pdf INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (series, number)
)
SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS documents_summary_idx ON documents (issue_date, submission, status)');
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS tickets (
    ticket TEXT PRIMARY KEY,
    kind TEXT NOT NULL,
    file_name TEXT NOT NULL,
    status TEXT NOT NULL,
    sunat_code TEXT,
    sunat_message TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS ticket_documents (
    ticket TEXT NOT NULL,
    external_id TEXT NOT NULL,
    action TEXT NOT NULL,
    PRIMARY KEY (ticket, external_id)
)
SQL);
        // Correlativos de los resúmenes diarios (RC) y comunicaciones de baja (RA): los lleva este servicio
        // porque son documentos propios, no series de comprobantes (esas son del API).
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS batch_sequences (
    kind TEXT NOT NULL,
    day TEXT NOT NULL,
    last_number INTEGER NOT NULL,
    PRIMARY KEY (kind, day)
)
SQL);
    }
}
