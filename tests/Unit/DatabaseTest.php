<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Unit;

use Desertica\Billing\Persistence\Database;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    public function testMigratesOnceAndRecordsTheVersion(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        Database::prepare($pdo);
        self::assertSame(2, (int) $pdo->query('SELECT MAX(version) FROM schema_version')?->fetchColumn());

        // Con la versión al día, una nueva preparación no vuelve a ejecutar el esquema.
        $pdo->exec('DROP TABLE locks');
        Database::prepare($pdo);
        self::assertFalse($pdo->query("SELECT 1 FROM sqlite_master WHERE name = 'locks'")?->fetchColumn());
    }

    public function testUpgradesADatabaseCreatedBeforeSchemaVersion(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE documents (external_id TEXT PRIMARY KEY, request_hash TEXT NOT NULL, request_json TEXT NOT NULL, doc_type TEXT NOT NULL, '
            . 'series TEXT NOT NULL, number INTEGER NOT NULL, issue_date TEXT NOT NULL, currency TEXT NOT NULL, submission TEXT NOT NULL, status TEXT NOT NULL, '
            . 'taxable_cents INTEGER NOT NULL, igv_cents INTEGER NOT NULL, total_cents INTEGER NOT NULL, file_name TEXT NOT NULL, hash TEXT, sunat_code TEXT, '
            . 'sunat_message TEXT, ticket TEXT, void_ticket TEXT, void_reason TEXT, has_xml INTEGER NOT NULL DEFAULT 0, has_cdr INTEGER NOT NULL DEFAULT 0, '
            . 'has_pdf INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (series, number))');

        $pdo->exec('CREATE TABLE locks (name TEXT PRIMARY KEY, until INTEGER NOT NULL)');

        Database::prepare($pdo);

        self::assertContains('owner', array_column($pdo->query('PRAGMA table_info(locks)')?->fetchAll(\PDO::FETCH_ASSOC) ?: [], 'name'));
        $columns = array_column($pdo->query('PRAGMA table_info(documents)')?->fetchAll(\PDO::FETCH_ASSOC) ?: [], 'name');
        self::assertContains('send_attempted', $columns);
    }
}
