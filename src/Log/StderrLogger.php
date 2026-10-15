<?php

declare(strict_types=1);

namespace Desertica\Billing\Log;

use Psr\Log\AbstractLogger;

/**
 * Logger JSON a stderr. Redacta cualquier clave que parezca un secreto.
 */
final class StderrLogger extends AbstractLogger
{
    private const SENSITIVE = ['password', 'secret', 'token', 'authorization', 'pass', 'clave'];

    /**
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $line = json_encode([
            'ts' => gmdate('c'),
            'level' => $level,
            'message' => (string) $message,
            'context' => $this->redact($context),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        file_put_contents('php://stderr', $line . PHP_EOL);
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            } elseif (is_object($value)) {
                $data[$key] = '[object ' . $value::class . ']';
            }
        }

        return $data;
    }

    private function isSensitive(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}
