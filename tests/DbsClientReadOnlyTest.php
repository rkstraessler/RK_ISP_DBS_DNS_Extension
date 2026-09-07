<?php

require_once __DIR__ . '/../src/dbsdns/lib/classes/DbsClient.inc.php';

class FakeDbsSoapClient
{
    public $domainListResponse;
    public $domainListResponses = array();
    public $zoneInfoResponse;
    public $rrCreateResponse;
    public $rrDeleteResponse;
    public $domainListCalls = 0;
    public $zoneInfoCalls = 0;
    public $rrCreateCalls = 0;
    public $rrDeleteCalls = 0;
    public $lastDomainListRequest;
    public $domainListRequests = array();
    public $lastZoneInfoRequest;
    public $lastRRCreateRequest;
    public $lastRRDeleteRequest;

    public function domainListExtended($request)
    {
        $this->domainListCalls++;
        $this->lastDomainListRequest = $request;
        $this->domainListRequests[] = $request;

        if(count($this->domainListResponses) >= $this->domainListCalls) {
            return $this->domainListResponses[$this->domainListCalls - 1];
        }

        return $this->domainListResponse;
    }

    public function nameserverZoneInfo($request)
    {
        $this->zoneInfoCalls++;
        $this->lastZoneInfoRequest = $request;

        return $this->zoneInfoResponse;
    }

    public function nameserverRRCreate($request)
    {
        $this->rrCreateCalls++;
        $this->lastRRCreateRequest = $request;

        return $this->rrCreateResponse;
    }

    public function nameserverRRDelete($request)
    {
        $this->rrDeleteCalls++;
        $this->lastRRDeleteRequest = $request;

        return $this->rrDeleteResponse;
    }
}

function assertSameValue($expected, $actual, $message)
{
    if($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function assertTrueValue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

function createSoapItemList($items)
{
    $list = new stdClass();

    if(count($items) === 1) {
        $list->item = $items[0];
    } else {
        $list->item = $items;
    }

    return $list;
}

function createDomainEntry($domainName, $domainNameAce, $domainNameIdn, $status)
{
    $fields = array();

    foreach(array(
        'domainName' => $domainName,
        'domainNameAce' => $domainNameAce,
        'domainNameIdn' => $domainNameIdn,
        'domainStatus' => $status
    ) as $key => $value) {
        $field = new stdClass();
        $field->key = $key;
        $field->value = $value;
        $fields[] = $field;
    }

    $entry = new stdClass();
    $entry->domainInfoItem = createSoapItemList($fields);

    return $entry;
}

function createClientWithFakeSoapClient($soapClient)
{
    $reflection = new ReflectionClass('DbsClient');
    $client = $reflection->newInstanceWithoutConstructor();
    $soapClientProperty = $reflection->getProperty('soapClient');

    if(PHP_VERSION_ID < 80100) {
        $soapClientProperty->setAccessible(true);
    }

    $soapClientProperty->setValue($client, $soapClient);

    return $client;
}

function expectDbsExceptionCode($callback, $expectedCode)
{
    try {
        call_user_func($callback);
    } catch (DbsClientException $exception) {
        assertSameValue($expectedCode, $exception->getCode(), 'Unerwarteter DbsClient-Fehlercode.');
        return;
    }

    throw new RuntimeException('Erwartete DbsClientException wurde nicht ausgelöst.');
}

$testFailure = null;

try {
    $soapClient = new FakeDbsSoapClient();
    $domainResponse = new stdClass();
    $domainResponse->returnCode = 1000;
    $domainResponse->domainInfo = createSoapItemList(array(
        createDomainEntry('example.de', 'example.de', '', 'active'),
        createDomainEntry('xn--bcher-kva.de', 'xn--bcher-kva.de', 'bücher.de', 'active'),
        createDomainEntry('example.net', 'example.net', '', 'active')
    ));
    $soapClient->domainListResponse = $domainResponse;

    $client = createClientWithFakeSoapClient($soapClient);
    $domainList = $client->listDomains(2, 0);

    assertSameValue(1, $soapClient->domainListCalls, 'Die Domainliste führt nicht genau einen SOAP-Aufruf aus.');
    assertSameValue('own', $soapClient->lastDomainListRequest['level'], 'Die Domainliste ist nicht auf den eigenen Account begrenzt.');
    assertSameValue('3', $soapClient->lastDomainListRequest['limit'], 'Der zusätzliche Datensatz für die Pagination fehlt.');
    assertSameValue('0', $soapClient->lastDomainListRequest['offset'], 'Der Domainlisten-Offset ist falsch.');
    assertSameValue(2, count($domainList['domains']), 'Die Domainliste wird nicht auf die Seitengröße begrenzt.');
    assertTrueValue($domainList['has_more'], 'Die nächste Seite wird nicht erkannt.');
    assertSameValue(false, $domainList['empty_inventory_confirmed'], 'Eine gefüllte DBS-Antwort wird als leeres Inventar markiert.');
    assertSameValue('bücher.de', $domainList['domains'][1]['domain_name'], 'Der IDN-Anzeigename wird nicht übernommen.');
    assertSameValue('xn--bcher-kva.de', $domainList['domains'][1]['origin'], 'Die ACE-Origin wird nicht für den Detailaufruf verwendet.');

    $pagedSoapClient = new FakeDbsSoapClient();
    $firstPageResponse = new stdClass();
    $firstPageResponse->returnCode = 1000;
    $firstPageResponse->domainInfo = createSoapItemList(array(
        createDomainEntry('one.example', 'one.example', '', 'active'),
        createDomainEntry('two.example', 'two.example', '', 'active'),
        createDomainEntry('three.example', 'three.example', '', 'active')
    ));
    $secondPageResponse = new stdClass();
    $secondPageResponse->returnCode = 1000;
    $secondPageResponse->domainInfo = createSoapItemList(array(
        createDomainEntry('three.example', 'three.example', '', 'active')
    ));
    $pagedSoapClient->domainListResponses = array($firstPageResponse, $secondPageResponse);
    $pagedClient = createClientWithFakeSoapClient($pagedSoapClient);
    $allDomains = $pagedClient->listAllDomains(2);

    assertSameValue(2, $pagedSoapClient->domainListCalls, 'Die vollständige Domainliste paginiert nicht korrekt.');
    assertSameValue(0, $pagedSoapClient->zoneInfoCalls, 'Die vollständige Domainliste ruft unerwartet Zoneninformationen ab.');
    assertSameValue('0', $pagedSoapClient->domainListRequests[0]['offset'], 'Der erste Offset der Gesamtliste ist falsch.');
    assertSameValue('2', $pagedSoapClient->domainListRequests[1]['offset'], 'Der zweite Offset der Gesamtliste ist falsch.');
    assertSameValue(3, count($allDomains), 'Die paginierten DBS-Domains werden nicht vollständig zusammengeführt.');
    assertSameValue('three.example', $allDomains[2]['origin'], 'Die letzte paginierte DBS-Domain fehlt.');

    $noResultsSoapClient = new FakeDbsSoapClient();
    $noResultsResponse = new stdClass();
    $noResultsResponse->returnCode = DbsClient::RETURN_CODE_NO_RESULTS;
    $noResultsSoapClient->domainListResponse = $noResultsResponse;
    $emptyInventory = createClientWithFakeSoapClient($noResultsSoapClient)->getDomainInventory(2);

    assertSameValue(array(), $emptyInventory['domains'], 'Ein ausdrücklich leeres DBS-Inventar enthält Domains.');
    assertSameValue(true, $emptyInventory['empty_inventory_confirmed'], 'RETURN_CODE_NO_RESULTS bestätigt kein leeres Inventar.');

    $unexpectedEmptySoapClient = new FakeDbsSoapClient();
    $unexpectedEmptyResponse = new stdClass();
    $unexpectedEmptyResponse->returnCode = DbsClient::RETURN_CODE_SUCCESS;
    $unexpectedEmptyResponse->domainInfo = createSoapItemList(array());
    $unexpectedEmptySoapClient->domainListResponse = $unexpectedEmptyResponse;
    $unexpectedEmptyClient = createClientWithFakeSoapClient($unexpectedEmptySoapClient);
    expectDbsExceptionCode(function() use ($unexpectedEmptyClient) {
        $unexpectedEmptyClient->getDomainInventory(2);
    }, DbsClient::ERROR_INVALID_RESPONSE);

    $inconsistentPagingSoapClient = new FakeDbsSoapClient();
    $inconsistentPagingSoapClient->domainListResponses = array(
        $firstPageResponse,
        $noResultsResponse
    );
    $inconsistentPagingClient = createClientWithFakeSoapClient($inconsistentPagingSoapClient);
    expectDbsExceptionCode(function() use ($inconsistentPagingClient) {
        $inconsistentPagingClient->getDomainInventory(2);
    }, DbsClient::ERROR_INVALID_RESPONSE);

    $zoneResponse = new stdClass();
    $zoneResponse->returnCode = 1000;
    $zoneResponse->origin = 'example.de';
    $zoneResponse->refresh = '28800';
    $zoneResponse->retry = '7200';
    $zoneResponse->expire = '604800';
    $zoneResponse->ttl = '86400';
    $zoneResponse->minimumTtl = '86400';
    $record = new stdClass();
    $record->type = 'MX';
    $record->name = 'example.de.';
    $record->data = 'mail.example.de.';
    $record->aux = '10';
    $record->ttl = '86400';
    $zoneResponse->rrList = createSoapItemList(array($record));
    $soapClient->zoneInfoResponse = $zoneResponse;

    $zone = $client->getZoneInfo('EXAMPLE.DE.');

    assertSameValue(1, $soapClient->zoneInfoCalls, 'Die Zonenansicht führt nicht genau einen SOAP-Aufruf aus.');
    assertSameValue('example.de', $soapClient->lastZoneInfoRequest['soaOrigin'], 'Die Origin wird nicht sicher normalisiert.');
    assertSameValue(1, count($zone['records']), 'Der DNS-Record wird nicht gelesen.');
    assertSameValue('MX', $zone['records'][0]['type'], 'Der DNS-Record-Typ ist falsch.');
    assertSameValue('10', $zone['records'][0]['aux'], 'Die DNS-Record-Priorität ist falsch.');

    $rrSuccessResponse = new stdClass();
    $rrSuccessResponse->returnCode = DbsClient::RETURN_CODE_SUCCESS;
    $soapClient->rrCreateResponse = $rrSuccessResponse;
    $soapClient->rrDeleteResponse = $rrSuccessResponse;
    $aRecord = array(
        'name' => 'www',
        'type' => 'A',
        'data' => '94.130.231.186',
        'aux' => '0',
        'ttl' => '3600'
    );

    $genericRecordCases = array(
        'A' => $aRecord,
        'AAAA' => array(
            'name' => 'ipv6',
            'type' => 'AAAA',
            'data' => '2001:db8::1',
            'aux' => '0',
            'ttl' => '3600'
        ),
        'CNAME' => array(
            'name' => 'alias',
            'type' => 'CNAME',
            'data' => 'target.example.net.',
            'aux' => '0',
            'ttl' => '7200'
        ),
        'MX' => array(
            'name' => 'example.de.',
            'type' => 'MX',
            'data' => 'mail.example.net.',
            'aux' => '10',
            'ttl' => '86400'
        ),
        'NS' => array(
            'name' => '',
            'type' => 'NS',
            'data' => 'ns.example.tld',
            'aux' => '0',
            'ttl' => '86400'
        ),
        'SRV' => array(
            'name' => '_service._tcp',
            'type' => 'SRV',
            'data' => '10 5060 target.example.tld',
            'aux' => '100',
            'ttl' => '3600'
        ),
        'TXT' => array(
            'name' => '',
            'type' => 'TXT',
            'data' => 'v=spf1 include:_spf.example -all path=\\raw value="quoted"',
            'aux' => '0',
            'ttl' => '3600'
        )
    );

    foreach($genericRecordCases as $type => $genericRecord) {
        $createCallsBeforeGeneric = $soapClient->rrCreateCalls;
        $client->createRecord('EXAMPLE.DE.', $genericRecord);
        assertSameValue(
            $createCallsBeforeGeneric + 1,
            $soapClient->rrCreateCalls,
            $type . '-Create führt nicht genau einen SOAP-Aufruf aus.'
        );
        assertSameValue(array(
            'soaOrigin' => 'example.de',
            'rr' => array('item' => array($genericRecord)),
            'clientTRID' => '',
            'forReseller' => ''
        ), $soapClient->lastRRCreateRequest, $type . ' wird nicht exakt auf nameserverRRCreate abgebildet.');

        $deleteCallsBeforeGeneric = $soapClient->rrDeleteCalls;
        $client->deleteRecord('EXAMPLE.DE.', $genericRecord);
        assertSameValue(
            $deleteCallsBeforeGeneric + 1,
            $soapClient->rrDeleteCalls,
            $type . '-Delete führt nicht genau einen SOAP-Aufruf aus.'
        );
        assertSameValue(array(
            'soaOrigin' => 'example.de',
            'rr' => $genericRecord,
            'clientTRID' => '',
            'forReseller' => ''
        ), $soapClient->lastRRDeleteRequest, $type . ' wird nicht exakt auf nameserverRRDelete abgebildet.');
        assertTrueValue(
            !isset($soapClient->lastRRCreateRequest['rr']['id'])
            && !isset($soapClient->lastRRDeleteRequest['rr']['id']),
            'Der DBS-Request erfindet eine nicht dokumentierte RR-ID.'
        );
    }

    foreach(array('CNAME', 'MX') as $fqdnType) {
        $withoutDot = $genericRecordCases[$fqdnType];
        $withoutDot['data'] = substr($withoutDot['data'], 0, -1);
        $client->createRecord('example.de', $withoutDot);
        assertSameValue(
            $genericRecordCases[$fqdnType],
            $soapClient->lastRRCreateRequest['rr']['item'][0],
            $fqdnType . ' normalisiert ein Ziel ohne abschließenden Punkt nicht ins DBS-Format.'
        );
    }

    $nsWithDot = $genericRecordCases['NS'];
    $nsWithDot['data'] .= '.';
    $client->createRecord('example.de', $nsWithDot);
    assertSameValue(
        $genericRecordCases['NS'],
        $soapClient->lastRRCreateRequest['rr']['item'][0],
        'NS entfernt den optionalen abschließenden Punkt nicht fürs DBS-Format.'
    );

    $existingProviderTxt = $genericRecordCases['TXT'];
    $existingProviderTxt['data'] = str_repeat('x', 600);
    $deleteCallsBeforeExistingTxt = $soapClient->rrDeleteCalls;
    $client->deleteRecord('example.de', $existingProviderTxt);
    assertSameValue(
        $deleteCallsBeforeExistingTxt + 1,
        $soapClient->rrDeleteCalls,
        'Ein bestehender Provider-TXT oberhalb der nativen Create-Grenze ist nicht löschbar.'
    );
    assertSameValue(
        $existingProviderTxt,
        $soapClient->lastRRDeleteRequest['rr'],
        'Ein bestehender Provider-TXT wird beim Delete verändert.'
    );

    $rrRejectedResponse = new stdClass();
    $rrRejectedResponse->returnCode = DbsClient::RETURN_CODE_NOT_FOUND;
    $soapClient->rrCreateResponse = $rrRejectedResponse;
    expectDbsExceptionCode(function() use ($client, $aRecord) {
        $client->createRecord('example.de', $aRecord);
    }, DbsClient::ERROR_REQUEST_REJECTED);
    $soapClient->rrDeleteResponse = $rrRejectedResponse;
    expectDbsExceptionCode(function() use ($client, $aRecord) {
        $client->deleteRecord('example.de', $aRecord);
    }, DbsClient::ERROR_REQUEST_REJECTED);

    $rrMalformedResponse = new stdClass();
    $rrMalformedResponse->returnCode = '1000unexpected';
    $soapClient->rrCreateResponse = $rrMalformedResponse;
    expectDbsExceptionCode(function() use ($client, $aRecord) {
        $client->createRecord('example.de', $aRecord);
    }, DbsClient::ERROR_INVALID_RESPONSE);

    $createCallsBeforeInvalid = $soapClient->rrCreateCalls;
    $invalidARecord = $aRecord;
    $invalidARecord['data'] = '999.1.1.1';
    expectDbsExceptionCode(function() use ($client, $invalidARecord) {
        $client->createRecord('example.de', $invalidARecord);
    }, DbsClient::ERROR_INVALID_INPUT);
    assertSameValue($createCallsBeforeInvalid, $soapClient->rrCreateCalls, 'Ungültige A-Daten lösen einen SOAP-Create aus.');

    $invalidGenericRecords = array(
        array('name' => 'alias', 'type' => 'ALIAS', 'data' => 'target.example.net.', 'aux' => '0', 'ttl' => '3600'),
        array('name' => 'alias', 'type' => 'CAA', 'data' => '0 issue example.net', 'aux' => '0', 'ttl' => '3600'),
        array('name' => '_443._tcp', 'type' => 'TLSA', 'data' => '3 1 1 abc', 'aux' => '0', 'ttl' => '3600'),
        array('name' => 'key', 'type' => 'OPENPGPKEY', 'data' => 'abc', 'aux' => '0', 'ttl' => '3600'),
        array('name' => 'ipv6', 'type' => 'AAAA', 'data' => 'not-an-ipv6-address', 'aux' => '0', 'ttl' => '3600'),
        array('name' => 'alias', 'type' => 'CNAME', 'data' => 'target.example.net..', 'aux' => '0', 'ttl' => '3600'),
        array('name' => 'example.de.', 'type' => 'MX', 'data' => 'mail.example.net.', 'aux' => '65536', 'ttl' => '3600'),
        array('name' => '', 'type' => 'NS', 'data' => 'ns.example.tld..', 'aux' => '0', 'ttl' => '3600'),
        array('name' => '_service._tcp', 'type' => 'SRV', 'data' => '100 10 5060 target.example.tld', 'aux' => '100', 'ttl' => '3600'),
        array('name' => '_service._tcp', 'type' => 'SRV', 'data' => '10 5060 target.example.tld', 'aux' => '65536', 'ttl' => '3600'),
        array('name' => '', 'type' => 'TXT', 'data' => "line one\nline two", 'aux' => '0', 'ttl' => '3600'),
        array('name' => '', 'type' => 'TXT', 'data' => '', 'aux' => '0', 'ttl' => '3600')
    );

    foreach($invalidGenericRecords as $invalidGenericRecord) {
        $callsBeforeInvalidGeneric = $soapClient->rrCreateCalls;
        expectDbsExceptionCode(function() use ($client, $invalidGenericRecord) {
            $client->createRecord('example.de', $invalidGenericRecord);
        }, DbsClient::ERROR_INVALID_INPUT);
        assertSameValue(
            $callsBeforeInvalidGeneric,
            $soapClient->rrCreateCalls,
            'Ungültige generische Recorddaten lösen einen SOAP-Create aus.'
        );
    }

    $mismatchedZoneResponse = clone $zoneResponse;
    $mismatchedZoneResponse->origin = 'foreign.example';
    $soapClient->zoneInfoResponse = $mismatchedZoneResponse;
    expectDbsExceptionCode(function() use ($client) {
        $client->getZoneInfo('example.de');
    }, DbsClient::ERROR_INVALID_RESPONSE);

    $callsBeforeInvalidInput = $soapClient->zoneInfoCalls;
    expectDbsExceptionCode(function() use ($client) {
        $client->getZoneInfo('../invalid');
    }, DbsClient::ERROR_INVALID_INPUT);
    assertSameValue($callsBeforeInvalidInput, $soapClient->zoneInfoCalls, 'Ungültige Origins lösen einen SOAP-Aufruf aus.');

    $zoneNotFoundResponse = new stdClass();
    $zoneNotFoundResponse->returnCode = 2303;
    $soapClient->zoneInfoResponse = $zoneNotFoundResponse;
    expectDbsExceptionCode(function() use ($client) {
        $client->getZoneInfo('missing.example');
    }, DbsClient::ERROR_ZONE_NOT_FOUND);

    $connectionSoapClient = new FakeDbsSoapClient();
    $connectionResponse = new stdClass();
    $connectionResponse->returnCode = 1000;
    $connectionSoapClient->domainListResponse = $connectionResponse;
    $connectionClient = createClientWithFakeSoapClient($connectionSoapClient);
    $connectionClient->testConnection();
    assertSameValue(1, $connectionSoapClient->domainListCalls, 'Der bestehende Verbindungstest führt nicht genau einen SOAP-Aufruf aus.');
    assertSameValue('1', $connectionSoapClient->lastDomainListRequest['limit'], 'Der bestehende Verbindungstest ist nicht mehr auf einen Datensatz begrenzt.');
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'DbsClient-Read-only-Tests erfolgreich.' . PHP_EOL;
