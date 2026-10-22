<?php

declare(strict_types=1);

namespace Desertica\Billing\Config;

/**
 * Envuelve un valor sensible para que no salga en logs, var_dump ni serializaciones.
 */
final class Secret
{
    /** @var \Closure(): string */
    private readonly \Closure $reveal;

    public function __construct(string $value)
    {
        // En una closure el valor no aparece en var_export, print_r ni var_dump.
        $this->reveal = static fn (): string => $value;
    }

    public function reveal(): string
    {
        return ($this->reveal)();
    }

    public function __toString(): string
    {
        return '***';
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => '***'];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        return ['value' => '***'];
    }
}
