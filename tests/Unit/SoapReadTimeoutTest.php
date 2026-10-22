<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Unit;

use Desertica\Billing\Sunat\VerifiedSoapClient;
use Greenter\Ws\Services\WsdlProvider;
use PHPUnit\Framework\TestCase;

final class SoapReadTimeoutTest extends TestCase
{
    public function testAServerThatNeverAnswersIsCutAtTheExplicitReadTimeout(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($server);
        $port = (int) parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT);
        $previous = ini_set('default_socket_timeout', '60');

        $client = new VerifiedSoapClient(WsdlProvider::getConsultPath(), null, 2, 1);
        $client->setService('http://127.0.0.1:' . $port . '/billConsultService');
        $start = microtime(true);
        set_error_handler(static fn (): bool => true);
        try {
            $client->call('getStatus', ['parameters' => ['rucComprobante' => '20000000001', 'tipoComprobante' => '01', 'serieComprobante' => 'F001', 'numeroComprobante' => 1]]);
            self::fail('Debía fallar por tiempo de espera.');
        } catch (\SoapFault) {
            self::assertLessThan(5, microtime(true) - $start);
        } finally {
            restore_error_handler();
            fclose($server);
        }

        self::assertSame('60', ini_get('default_socket_timeout'), 'El límite global debe restaurarse.');
        ini_set('default_socket_timeout', (string) $previous);
    }
}
