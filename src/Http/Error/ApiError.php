<?php

declare(strict_types=1);

namespace Desertica\Billing\Http\Error;

/**
 * Error con el formato del contrato: {code, message, sunatCode?, retryable?}.
 */
final class ApiError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?string $sunatCode = null,
        public readonly ?bool $retryable = null,
    ) {
        parent::__construct($message);
    }

    public static function unauthorized(): self
    {
        return new self(401, 'UNAUTHORIZED', 'Token de servicio ausente o inválido.');
    }

    public static function notFound(string $message = 'Recurso no encontrado.'): self
    {
        return new self(404, 'NOT_FOUND', $message);
    }

    public static function badRequest(string $message, string $code = 'BAD_REQUEST'): self
    {
        return new self(400, $code, $message);
    }

    public static function unprocessable(string $code, string $message, ?string $sunatCode = null): self
    {
        return new self(422, $code, $message, $sunatCode, false);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }

    public static function upstream(string $message, ?string $sunatCode = null): self
    {
        return new self(502, 'SUNAT_UNAVAILABLE', $message, $sunatCode, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toBody(): array
    {
        $body = ['code' => $this->errorCode, 'message' => $this->getMessage()];
        if ($this->sunatCode !== null) {
            $body['sunatCode'] = $this->sunatCode;
        }
        if ($this->retryable !== null) {
            $body['retryable'] = $this->retryable;
        }

        return $body;
    }
}
