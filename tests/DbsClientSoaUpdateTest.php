<?php

require_once __DIR__ . '/../src/dbsdns/lib/classes/DbsClient.inc.php';

class SoaUpdateSoapClient
{
    public $soaCalls = 0;
    public $rrCreateCalls = 0;
    public $lastSoaRequest;
    public $lastCreateRequest;
    public $soaResponse;
    public $createResponse;

    public function nameserverSOAUpdate($request)
    {
        $this->soaCalls++;
        $this->lastSoaRequest = $request;

        return $this->soaResponse;
    }

    public function nameserverRRCreate($request)
    {
        $this->rrCreateCalls++;
        $this->lastCreateRequest = $request;

        return $this->createResponse;
    }
}

function soaClient($soap)
{
    $reflection = new ReflectionClass('DbsClient');
    $client = $reflection->newInstanceWithoutConstructor();
    $property = $reflection->getProperty('soapClient');

    if(PHP_VERSION_ID < 80100) {
        $property->setAccessible(true);
    }

    $property->setValue($client, $soap);

    return $client;
}

function soaAssertSame($expected, $actual, $message)
{
    if($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function soaExpect($callback, $code)
{
    try {
        $callback();
    } catch (DbsClientException $exception) {
        soaAssertSame($code, $exception->getCode(), 'Unerwarteter DbsClient-SOA-Fehlercode.');
        return;
    }

    throw new RuntimeException('Die erwartete DbsClient-SOA-Exception blieb aus.');
}

$testFailure = null;

try {
    $soap = new SoaUpdateSoapClient();
    $success = new stdClass();
    $success->returnCode = DbsClient::RETURN_CODE_SUCCESS;
    $soap->soaResponse = $success;
    $soap->createResponse = $success;
    $client = soaClient($soap);
    $settings = array(
        'mbox' => 'hostmaster.example.de',
        'refresh' => '28800',
        'retry' => '7200',
        'expire' => '604800',
        'minimum_ttl' => '86400',
        'ttl' => '3600'
    );
    $client->updateZoneSettings('EXAMPLE.DE.', $settings);

    soaAssertSame(1, $soap->soaCalls, 'nameserverSOAUpdate wird nicht genau einmal aufgerufen.');
    soaAssertSame(array(
        'soaOrigin' => 'example.de',
        'soaExpire' => '604800',
        'soaMbox' => 'hostmaster.example.de',
        'soaMinimum' => '86400',
        'soaRefresh' => '28800',
        'soaRetry' => '7200',
        'soaTtl' => '3600',
        'clientTRID' => '',
        'forReseller' => ''
    ), $soap->lastSoaRequest, 'Der nameserverSOAUpdate-Request entspricht nicht dem offiziellen Vertrag.');
    soaAssertSame(false, isset($soap->lastSoaRequest['soaPrimary']), 'Das im PDF-Beispiel bewusst nicht übertragene WSDL-Primary-Feld wird geschrieben.');

    foreach(array(
        array_replace($settings, array('mbox' => 'bad mailbox')),
        array_replace($settings, array('refresh' => '-1')),
        array_replace($settings, array('ttl' => '2147483648'))
    ) as $invalid) {
        $callsBefore = $soap->soaCalls;
        soaExpect(function() use ($client, $invalid) {
            $client->updateZoneSettings('example.de', $invalid);
        }, DbsClient::ERROR_INVALID_INPUT);
        soaAssertSame($callsBefore, $soap->soaCalls, 'Ungültige SOA-Werte lösen einen SOAP-Write aus.');
    }

    $rejected = new stdClass();
    $rejected->returnCode = DbsClient::RETURN_CODE_NOT_FOUND;
    $soap->soaResponse = $rejected;
    soaExpect(function() use ($client, $settings) {
        $client->updateZoneSettings('example.de', $settings);
    }, DbsClient::ERROR_REQUEST_REJECTED);

    $longTxt = array(
        'name' => '',
        'type' => 'TXT',
        'data' => str_repeat('x', 600),
        'aux' => '0',
        'ttl' => '3600'
    );
    $client->restoreRecord('example.de', $longTxt);
    soaAssertSame(1, $soap->rrCreateCalls, 'Der eng gekapselte Restore ruft nameserverRRCreate nicht auf.');
    soaAssertSame(array('item' => array($longTxt)), $soap->lastCreateRequest['rr'], 'Der signierte alte Provider-Tupel wird beim Restore verändert.');

    $source = file_get_contents(__DIR__ . '/../src/dbsdns/lib/classes/DbsClient.inc.php');
    soaAssertSame(true,
        strpos($source, 'nameserverRRUpdate') === false
        && strpos($source, 'nameserverSOAUpdate') !== false,
        'Der Client nimmt eine nicht vorhandene RR-Update-Methode an oder verliert SOAUpdate.'
    );
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Offizieller DBS-SOA-Request und eng gekapselter RR-Restore erfolgreich geprüft.' . PHP_EOL;
