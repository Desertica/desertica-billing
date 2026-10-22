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
     * @return string|null el token del propietario (hay que devolverlo a `release`), o `null` si el cerrojo está tomado
     */
    public function acquire(string $name, int $now, int $ttl): ?string
    {
        $owner = bin2hex(random_bytes(8));
        // Primero se intenta tomar un cerrojo caducado; si no existe fila, se inserta (la clave primaria decide quién gana).
        $take = $this->pdo->prepare('UPDATE locks SET until = :until, owner = :owner WHERE name = :name AND until <= :now');
        $take->execute(['until' => $now + $ttl, 'owner' => $owner, 'name' => $name, 'now' => $now]);
        if ($take->rowCount() === 1) {
            return $owner;
        }

        try {
            $this->pdo->prepare('INSERT INTO locks (name, until, owner) VALUES (:name, :until, :owner)')
                ->execute(['name' => $name, 'until' => $now + $ttl, 'owner' => $owner]);
        } catch (\PDOException) {
            return null;
        }

        return $owner;
    }

    /**
     * Solo suelta el cerrojo si sigue siendo del propietario: si caducó y otra petición lo tomó, no se lo quita.
     */
    public function release(string $name, string $owner): void
    {
        $this->pdo->prepare('DELETE FROM locks WHERE name = :name AND owner = :owner')->execute(['name' => $name, 'owner' => $owner]);
    }
}
