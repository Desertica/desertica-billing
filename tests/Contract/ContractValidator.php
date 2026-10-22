<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Contract;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Valida respuestas reales contra `openapi/billing.yaml` (copia del contrato del API). Sin red.
 *
 * Reglas: la ruta y el método deben existir en el contrato; el código de estado debe estar documentado para esa operación
 * y el cuerpo JSON debe cumplir el esquema de esa respuesta. Una respuesta que el contrato no prevé es una divergencia.
 */
final class ContractValidator
{
    private const SCHEMA_URI = 'urn:desertica:billing-contract';

    /** @var array<string, mixed> */
    private array $spec;
    private Validator $validator;

    public function __construct(?string $path = null)
    {
        $path ??= getenv('BILLING_CONTRACT_PATH') ?: dirname(__DIR__, 2) . '/openapi/billing.yaml';
        $spec = Yaml::parseFile($path);
        if (!is_array($spec) || !isset($spec['paths']) || !is_array($spec['paths'])) {
            throw new \RuntimeException('El contrato no tiene `paths`: ' . $path);
        }
        $this->spec = $spec;
        $this->validator = new Validator();
        $this->validator->resolver()?->registerRaw(
            json_decode(json_encode($spec, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
            self::SCHEMA_URI,
        );
    }

    /**
     * Devuelve la lista de divergencias (vacía si cumple). Las rutas o métodos que el contrato no define devuelven `null`:
     * no hay nada contra qué validar (404 de ruta desconocida, 405).
     *
     * @return list<string>|null
     */
    public function violations(string $method, string $path, ResponseInterface $response): ?array
    {
        $operation = $this->operation($method, $path);
        if ($operation === null) {
            return null;
        }

        $status = (string) $response->getStatusCode();
        $declared = $operation['responses'][$status] ?? null;
        if (!is_array($declared)) {
            return [sprintf('%s %s respondió %s, que el contrato no documenta (documenta: %s).', $method, $path, $status, implode(', ', array_keys($operation['responses'])))];
        }
        $declared = $this->deref($declared);

        $content = $declared['content'] ?? [];
        if (!is_array($content) || $content === []) {
            return [];
        }
        $mime = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
        if (!isset($content[$mime])) {
            return [sprintf('%s %s %s: Content-Type «%s» no está en el contrato (%s).', $method, $path, $status, $mime, implode(', ', array_keys($content)))];
        }
        if ($mime !== 'application/json') {
            return [];
        }

        $body = (string) $response->getBody();
        $response->getBody()->rewind();
        try {
            $data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [sprintf('%s %s %s: el cuerpo no es JSON (%s).', $method, $path, $status, $e->getMessage())];
        }

        $schema = $content[$mime]['schema'] ?? null;
        if (!is_array($schema)) {
            return [];
        }
        $result = $this->validator->validate($data, json_decode(json_encode($this->absolute($schema), JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR));
        if ($result->isValid()) {
            return [];
        }
        $error = $result->error();
        $flat = $error === null ? ['/' => 'inválido'] : (new ErrorFormatter())->format($error, false);
        $messages = [];
        foreach ($flat as $pointer => $message) {
            $messages[] = sprintf('%s %s %s → %s: %s', $method, $path, $status, $pointer, $message);
        }

        return $messages;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function operation(string $method, string $path): ?array
    {
        $path = '/' . trim($path, '/');
        foreach ($this->spec['paths'] as $template => $item) {
            $regex = '#^' . preg_replace('#\\\{[^/]+\\\}#', '[^/]+', preg_quote((string) $template, '#')) . '$#';
            if (preg_match($regex, $path) !== 1 || !is_array($item)) {
                continue;
            }
            $operation = $item[strtolower($method)] ?? null;

            return is_array($operation) && isset($operation['responses']) && is_array($operation['responses']) ? $operation : null;
        }

        return null;
    }

    /**
     * @param array<mixed> $node
     * @return array<mixed>
     */
    private function deref(array $node): array
    {
        while (isset($node['$ref']) && is_string($node['$ref']) && str_starts_with($node['$ref'], '#/')) {
            $target = $this->spec;
            foreach (explode('/', substr($node['$ref'], 2)) as $part) {
                $target = is_array($target) ? ($target[$part] ?? null) : null;
            }
            if (!is_array($target)) {
                throw new \RuntimeException('Referencia rota en el contrato: ' . $node['$ref']);
            }
            $node = $target;
        }

        return $node;
    }

    /**
     * Reescribe los `$ref: '#/...'` locales para que apunten al contrato registrado.
     *
     * @param array<mixed> $node
     * @return array<mixed>
     */
    private function absolute(array $node): array
    {
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && str_starts_with($value, '#/')) {
                $node[$key] = self::SCHEMA_URI . $value;
            } elseif (is_array($value)) {
                $node[$key] = $this->absolute($value);
            }
        }

        return $node;
    }
}
