<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Unit;

use Desertica\Billing\Sunat\GreenterSunatGateway;
use Desertica\Billing\Sunat\SunatException;
use Desertica\Billing\Sunat\TicketStatus;
use Greenter\Model\Response\BillResult;
use Greenter\Model\Response\CdrResponse;
use Greenter\Model\Response\Error;
use Greenter\Model\Response\StatusResult;
use Greenter\Model\Response\SummaryResult;
use Greenter\See;
use PHPUnit\Framework\TestCase;

/**
 * El gateway real contra un `See` simulado: comprueba cómo se traducen las respuestas de SUNAT sin tocar la red.
 */
final class GreenterSunatGatewayTest extends TestCase
{
    public function testAcceptedCdr(): void
    {
        $result = (new BillResult())->setSuccess(true)->setCdrZip('ZIP')->setCdrResponse(
            (new CdrResponse())->setCode('0')->setDescription('Aceptada')->setNotes(['4287 obs']),
        );
        $see = $this->createMock(See::class);
        $see->method('sendXmlFile')->willReturn($result);

        $cdr = (new GreenterSunatGateway($see))->sendDocument('<xml/>');

        self::assertTrue($cdr->accepted);
        self::assertSame('0', $cdr->code);
        self::assertSame(['4287 obs'], $cdr->notes);
        self::assertSame('ZIP', $cdr->zip);
    }

    public function testRejectedCdr(): void
    {
        $result = (new BillResult())->setSuccess(true)->setCdrZip('ZIP')->setCdrResponse(
            (new CdrResponse())->setCode('2335')->setDescription('Rechazada'),
        );
        $see = $this->createMock(See::class);
        $see->method('sendXmlFile')->willReturn($result);

        $cdr = (new GreenterSunatGateway($see))->sendDocument('<xml/>');

        self::assertFalse($cdr->accepted);
        self::assertSame('2335', $cdr->code);
    }

    public function testSunatExceptionCodeIsNotRetryable(): void
    {
        $result = (new BillResult())->setSuccess(false)->setError(new Error('0151', 'Nombre de archivo inválido'));
        $see = $this->createMock(See::class);
        $see->method('sendXmlFile')->willReturn($result);

        try {
            (new GreenterSunatGateway($see))->sendDocument('<xml/>');
            self::fail('Se esperaba SunatException.');
        } catch (SunatException $e) {
            self::assertFalse($e->retryable);
            self::assertSame('0151', $e->sunatCode);
        }
    }

    public function testErrorWithoutNumericCodeIsRetryable(): void
    {
        foreach ([new Error('HTTP', 'Bad Gateway'), new Error('', 'Could not connect to host'), null] as $error) {
            $e = GreenterSunatGateway::exceptionFor($error);

            self::assertTrue($e->retryable);
        }
    }

    public function testSoapFaultAndTransportExceptionsAreRetryable(): void
    {
        foreach ([new \SoapFault('HTTP', 'Could not connect to host'), new \RuntimeException('zip roto')] as $failure) {
            $see = $this->createMock(See::class);
            $see->method('sendXmlFile')->willThrowException($failure);

            try {
                (new GreenterSunatGateway($see))->sendDocument('<xml/>');
                self::fail('Se esperaba SunatException.');
            } catch (SunatException $e) {
                self::assertTrue($e->retryable);
            }
        }
    }

    public function testMissingCdrIsRetryable(): void
    {
        $see = $this->createMock(See::class);
        $see->method('sendXmlFile')->willReturn((new BillResult())->setSuccess(true));

        $this->expectException(SunatException::class);
        (new GreenterSunatGateway($see))->sendDocument('<xml/>');
    }

    public function testBatchReturnsTicket(): void
    {
        $see = $this->createMock(See::class);
        $see->method('sendXmlFile')->willReturn((new SummaryResult())->setSuccess(true)->setTicket('202600001234'));

        self::assertSame('202600001234', (new GreenterSunatGateway($see))->sendBatch('<xml/>'));
    }

    public function testTicketStates(): void
    {
        $processed = (new StatusResult())->setSuccess(true)->setCode('0')->setCdrZip('ZIP')->setCdrResponse(
            (new CdrResponse())->setCode('0')->setDescription('Procesado'),
        );
        $pending = (new StatusResult())->setCode('98')->setError(new Error('98', 'aún no ha terminado'));
        $withErrors = (new StatusResult())->setCode('99')->setCdrZip('ZIP')->setError(new Error('2000', 'errores'));

        $see = $this->createMock(See::class);
        $see->method('getStatus')->willReturnOnConsecutiveCalls($processed, $pending, $withErrors);
        $gateway = new GreenterSunatGateway($see);

        self::assertSame(TicketStatus::ACCEPTED, $gateway->ticketStatus('T1')->state);
        self::assertSame(TicketStatus::PENDING, $gateway->ticketStatus('T1')->state);
        self::assertSame(TicketStatus::REJECTED, $gateway->ticketStatus('T1')->state);
    }
}
