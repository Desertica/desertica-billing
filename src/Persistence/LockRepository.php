<?php

declare(strict_types=1);

namespace Desertica\Billing\Persistence;

/**
 * Cerrojos con caducidad en la base de datos, compartidos entre los procesos de php-fpm.
 *
 * Evitan que dos peticiones trabajen a la vez sobre el mismo comprobante: de lo contrario ambas podrían firmar el XML
 * (con firmas distintas) y mandar a SUNAT dos versiones del mismo número. La caducidad cubre un proceso que muere sin soltarlo.
 */
final class LockRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @param int $now segundos Unix
     * @param int $ttl segundos de vigencia; debe superar el tiempo máximo de una petición
     */
    public function acquire(string $name, int $now, int $ttl): bool
    {
        // Primero se intenta tomar un cerrojo caducado; si no existe fila, se inserta (la clave primaria decide quién gana).
        $take = $this->pdo->prepare('UPDATE locks SET until = :until WHERE name = :name AND until <= :now');
        $take->execute(['until' => $now + $ttl, 'name' => $name, 'now' => $now]);
        if ($take->rowCount() === 1) {
            return true;
        }

        try {
            $this->pdo->prepare('INSERT INTO locks (name, until) VALUES (:name, :until)')
                ->execute(['name' => $name, 'until' => $now + $ttl]);
        } catch (\PDOException) {
            return false;
        }

        return true;
    }

    public function release(string $name): void
    {
        $this->pdo->prepare('DELETE FROM locks WHERE name = :name')->execute(['name' => $name]);
    }
}
