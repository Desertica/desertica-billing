<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * El validador del contrato debe fallar cuando una respuesta diverge; si no, las pruebas HTTP no protegerían nada.
 */
final class ContractValidatorTest extends TestCase
{
    private const ID = '11111111-1111-4111-8111-111111111111';

    /**
     * @param array<mixed> $body
     */
    private function response(int $status, array $body, string $type = 'application/json'): \Psr\Http\Message\ResponseInterface
    {
        $response = (new ResponseFactory())->createResponse($status)->withHeader('Content-Type', $type);
        $response->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $response;
    }

    public function testAcceptsAConformingResult(): void
    {
        $violations = (new ContractValidator())->violations('GET', '/v1/documents/' . self::ID, $this->response(200, [
            'externalId' => self::ID, 'status' => 'ACCEPTED', 'taxableCents' => 10000, 'igvCents' => 1800, 'totalCents' => 11800,
            'hash' => 'abc', 'sunatCode' => '0', 'sunatMessage' => null, 'ticket' => null,
            'files' => ['xml' => true, 'cdr' => true, 'pdf' => false],
        ]));

        self::assertSame([], $violations);
    }

    public function testRejectsAnUnknownStatusValue(): void
    {
        $violations = (new ContractValidator())->violations('GET', '/v1/documents/' . self::ID, $this->response(200, [
            'externalId' => self::ID, 'status' => 'DONE',
        ]));

        self::assertNotEmpty($violations);
    }

    public function testRejectsANullHashBecauseTheContractSaysString(): void
    {
        $violations = (new ContractValidator())->violations('GET', '/v1/documents/' . self::ID, $this->response(200, [
            'externalId' => self::ID, 'status' => 'PENDING', 'hash' => null,
        ]));

        self::assertNotEmpty($violations);
    }

    public function testRejectsAnErrorCodeOutsideTheEnum(): void
    {
        $violations = (new ContractValidator())->violations('POST', '/v1/documents', $this->response(422, ['code' => 'BAD_REQUEST', 'message' => 'x']));

        self::assertNotEmpty($violations);
    }

    public function testRejectsAStatusTheOperationDoesNotDocument(): void
    {
        $violations = (new ContractValidator())->violations('GET', '/v1/tickets/ABC', $this->response(409, ['code' => 'VOID_CONFLICT', 'message' => 'x']));

        self::assertNotEmpty($violations);
        self::assertStringContainsString('no documenta', (string) $violations[0]);
    }

    public function testRejectsAMissingContentTypeMatch(): void
    {
        $violations = (new ContractValidator())->violations('GET', '/v1/documents/' . self::ID . '/files/pdf', $this->response(200, [], 'application/json'));

        self::assertNotEmpty($violations);
    }

    public function testIgnoresPathsAndMethodsTheContractDoesNotDefine(): void
    {
        self::assertNull((new ContractValidator())->violations('GET', '/nope', $this->response(404, ['code' => 'NOT_FOUND', 'message' => 'x'])));
        self::assertNull((new ContractValidator())->violations('DELETE', '/v1/documents', $this->response(405, ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'x'])));
    }
}
