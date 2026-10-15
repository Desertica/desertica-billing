<?php

declare(strict_types=1);

namespace Desertica\Billing\Log;

use Psr\Log\LoggerInterface;

/**
 * Lleva los errores de PHP (avisos, excepciones sin capturar, fatales) al mismo log JSON a stderr que usa la aplicación.
 * Solo clase, mensaje, archivo y línea: nunca la traza, que puede arrastrar el XML, el certificado o la clave SOL.
 */
final class PhpErrorLogging
{
    public static function register(LoggerInterface $logger): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($logger): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            $logger->log(self::level($severity), 'php_error', ['message' => $message, 'file' => $file, 'line' => $line]);

            return true;
        });

        set_exception_handler(static function (\Throwable $e) use ($logger): void {
            $logger->critical('uncaught_exception', ['class' => $e::class, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            if (PHP_SAPI !== 'cli' && !headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['code' => 'INTERNAL_ERROR', 'message' => 'Error interno.', 'retryable' => false]);
            }
        });

        register_shutdown_function(static function () use ($logger): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $logger->critical('fatal_error', ['message' => $error['message'], 'file' => $error['file'], 'line' => $error['line']]);
            }
        });
    }

    public static function level(int $severity): string
    {
        return match (true) {
            ($severity & (E_NOTICE | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED)) !== 0 => 'notice',
            ($severity & (E_WARNING | E_USER_WARNING)) !== 0 => 'warning',
            default => 'error',
        };
    }
}
