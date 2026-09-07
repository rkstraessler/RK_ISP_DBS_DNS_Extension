<?php

require_once __DIR__ . '/../src/dbsdns/lib/classes/DbsRecordService.inc.php';

class FakeDbsRecordZoneAccess
{
    public $calls = array();

    public function getAccessibleZoneByCacheId($context, $cacheId)
    {
        $this->calls[] = array($context, $cacheId);

        if(
            !is_array($context) ||
            !isset($context['allowed_cache_ids']) ||
            !in_array((int)$cacheId, $context['allowed_cache_ids'], true)
        ) {
            return null;
        }

        return array(
            'cache_id' => (int)$cacheId,
            'normalized_domain' => (int)$cacheId === 42 ? 'example.de' : 'other.example'
        );
    }
}

class FakeDbsRecordClient
{
    public $createCalls = array();
    public $deleteCalls = array();
    public $zoneInfoCalls = array();
    public $liveZone = array('origin' => 'example.de', 'records' => array());
    public $createException;
    public $deleteException;
    public $applyCreate = true;
    public $applyDelete = true;

    public function createRecord($origin, $record)
    {
        $this->createCalls[] = array($origin, $record);

        if($this->createException !== null) {
            throw $this->createException;
        }

        if(!$this->applyCreate) {
            return;
        }

        $this->liveZone = array(
            'origin' => $origin,
            'records' => array_merge($this->liveZone['records'], array($record))
        );
    }

    public function deleteRecord($origin, $record)
    {
        $this->deleteCalls[] = array($origin, $record);

        if($this->deleteException !== null) {
            throw $this->deleteException;
        }

        if(!$this->applyDelete) {
            return;
        }

        $normalizer = new DbsRecordNormalizer();
        $expected = $normalizer->normalizeProviderRecord($record, $origin);
        $remaining = array();

        foreach($this->liveZone['records'] as $candidate) {
            if($normalizer->normalizeProviderRecord($candidate, $origin) !== $expected) {
                $remaining[] = $candidate;
            }
        }

        $this->liveZone = array('origin' => $origin, 'records' => $remaining);
    }

    public function getZoneInfo($origin)
    {
        $this->zoneInfoCalls[] = $origin;

        return $this->liveZone;
    }
}

function dbsRecordServiceAssertSame($expected, $actual, $message)
{
    if($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function dbsRecordServiceAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

function dbsRecordServiceExpectException($callback, $class, $code)
{
    try {
        call_user_func($callback);
    } catch (Throwable $exception) {
        dbsRecordServiceAssertTrue(
            $exception instanceof $class,
            'Unerwartete Exception-Klasse: ' . get_class($exception)
        );
        dbsRecordServiceAssertSame($code, $exception->getCode(), 'Unerwarteter Exception-Code.');
        return;
    }

    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
    $line = isset($trace[0]['line']) ? (int)$trace[0]['line'] : 0;
    throw new RuntimeException('Erwartete Exception wurde in Zeile ' . $line . ' nicht ausgelöst.');
}

$testFailure = null;

try {
    $identity = new DbsRecordIdentity(str_repeat('a', 64));
    $zoneAccess = new FakeDbsRecordZoneAccess();
    $client = new FakeDbsRecordClient();
    $factoryCalls = 0;
    $factory = function() use ($client, &$factoryCalls) {
        $factoryCalls++;
        return $client;
    };
    $service = new DbsRecordService($zoneAccess, $identity, $factory);
    $txtData = 'v=spf1 include:_spf.example -all path=\\raw value="quoted"';
    $cases = array(
        'A' => array(
            'input' => array('name' => 'www', 'type' => 'A', 'data' => '94.130.231.186', 'aux' => '0', 'ttl' => '3600', 'active' => 'Y'),
            'provider' => array('name' => 'www', 'type' => 'A', 'data' => '94.130.231.186', 'aux' => '0', 'ttl' => '3600'),
            'invalid_data' => '999.1.1.1',
            'invalid_aux' => '1'
        ),
        'AAAA' => array(
            'input' => array('name' => 'ipv6', 'type' => 'AAAA', 'data' => '2001:db8::1', 'aux' => '0', 'ttl' => '3600', 'active' => 'Y'),
            'provider' => array('name' => 'ipv6', 'type' => 'AAAA', 'data' => '2001:db8::1', 'aux' => '0', 'ttl' => '3600'),
            'invalid_data' => '94.130.231.186',
            'invalid_aux' => '1'
        ),
        'CNAME' => array(
            'input' => array('name' => 'alias', 'type' => 'CNAME', 'data' => 'target.example.net', 'aux' => '0', 'ttl' => '3600', 'active' => 'Y'),
            'provider' => array('name' => 'alias', 'type' => 'CNAME', 'data' => 'target.example.net.', 'aux' => '0', 'ttl' => '3600'),
            'invalid_data' => 'target without fqdn',
            'invalid_aux' => '1'
        ),
        'MX' => array(
            'input' => array('name' => '@', 'type' => 'MX', 'data' => 'mail.example.net', 'aux' => '10', 'ttl' => '3600', 'active' => 'Y'),
            'provider' => array('name' => 'example.de.', 'type' => 'MX', 'data' => 'mail.example.net.', 'aux' => '10', 'ttl' => '3600'),
            'invalid_data' => 'mail target',
            'invalid_aux' => '65536'
        ),
        'NS' => array(
            'input' => array('name' => '@', 'type' => 'NS', 'data' => 'NS.EXAMPLE.NET.', 'aux' => '0', 'ttl' => '86400', 'active' => 'Y'),
            'provider' => array('name' => '', 'type' => 'NS', 'data' => 'ns.example.net', 'aux' => '0', 'ttl' => '86400'),
            'invalid_data' => 'not-a-fqdn',
            'invalid_aux' => '1'
        ),
        'SRV' => array(
            'input' => array('name' => '_SERVICE._TCP', 'type' => 'SRV', 'data' => '10 5060 TARGET.EXAMPLE.NET.', 'aux' => '100', 'ttl' => '3600', 'active' => 'Y'),
            'provider' => array('name' => '_service._tcp', 'type' => 'SRV', 'data' => '10 5060 target.example.net', 'aux' => '100', 'ttl' => '3600'),
            'invalid_data' => '100 10 5060 target.example.net',
            'invalid_aux' => '65536'
        ),
        'TXT' => array(
            'input' => array('name' => '@', 'type' => 'TXT', 'data' => $txtData, 'aux' => '0', 'ttl' => '3600', 'active' => 'Y'),
            'provider' => array('name' => '', 'type' => 'TXT', 'data' => $txtData, 'aux' => '0', 'ttl' => '3600'),
            'invalid_data' => '',
            'invalid_aux' => '1'
        )
    );

    dbsRecordServiceAssertSame(
        array_keys($cases),
        DbsRecordCapabilities::supportedWritableRecordTypes(),
        'Die freigegebene Recordtyp-Allowlist ist unerwartet.'
    );

    foreach($cases as $type => $case) {
        foreach(array('admin', 'reseller', 'client') as $role) {
            $client->liveZone = array('origin' => 'example.de', 'records' => array());
            $result = $service->create(
                array('role' => $role, 'allowed_cache_ids' => array(42)),
                -42,
                $case['input']
            );
            dbsRecordServiceAssertSame(-42, $result['virtual_zone_id'], $type . ' verliert die virtuelle Zone beim Create.');
            dbsRecordServiceAssertSame($case['provider'], $result['record'], $type . ' wird nicht exakt in den DBS-Tupel abgebildet.');
        }

        $lastCreate = $client->createCalls[count($client->createCalls) - 1];
        dbsRecordServiceAssertSame(
            array('example.de', $case['provider']),
            $lastCreate,
            $type . ' wird verändert an den DBS-Client übergeben.'
        );

        foreach(array(
            array('field' => 'name', 'value' => 'bad host'),
            array('field' => 'data', 'value' => $case['invalid_data']),
            array('field' => 'aux', 'value' => $case['invalid_aux']),
            array('field' => 'ttl', 'value' => '59'),
            array('field' => 'ttl', 'value' => '3600x'),
            array('field' => 'active', 'value' => 'N')
        ) as $invalid) {
            $record = $case['input'];
            $record[$invalid['field']] = $invalid['value'];
            $callsBeforeInvalidCreate = $factoryCalls;
            dbsRecordServiceExpectException(function() use ($service, $record) {
                $service->create(array('allowed_cache_ids' => array(42)), -42, $record);
            }, 'DbsRecordException', DbsRecordService::ERROR_INVALID_RECORD);
            dbsRecordServiceAssertSame(
                $callsBeforeInvalidCreate,
                $factoryCalls,
                'Ungültige ' . $type . '-Formulardaten lösen einen DBS-Aufruf aus.'
            );
        }

        $callsBeforeDeniedCreate = $factoryCalls;
        dbsRecordServiceExpectException(function() use ($service, $case) {
            $service->create(array('allowed_cache_ids' => array()), -42, $case['input']);
        }, 'DbsRecordException', DbsRecordService::ERROR_ACCESS_DENIED);
        dbsRecordServiceAssertSame($callsBeforeDeniedCreate, $factoryCalls, 'Eine fremde Domain erzeugt einen DBS-Client.');

        dbsRecordServiceExpectException(function() use ($service, $case) {
            $service->create(array('allowed_cache_ids' => array(42)), 42, $case['input']);
        }, 'DbsRecordException', DbsRecordService::ERROR_INVALID_ZONE);

        foreach(array('-42tampered', '-999999999999999999999999999999') as $manipulatedZoneId) {
            dbsRecordServiceExpectException(function() use ($service, $case, $manipulatedZoneId) {
                $service->create(
                    array('allowed_cache_ids' => array(42)),
                    $manipulatedZoneId,
                    $case['input']
                );
            }, 'DbsRecordException', DbsRecordService::ERROR_INVALID_ZONE);
        }

        $client->createException = new DbsClientException('safe failure', DbsClient::ERROR_REQUEST_REJECTED);
        $client->liveZone = array('origin' => 'example.de', 'records' => array());
        dbsRecordServiceExpectException(function() use ($service, $case) {
            $service->create(array('allowed_cache_ids' => array(42)), -42, $case['input']);
        }, 'DbsClientException', DbsClient::ERROR_REQUEST_REJECTED);
        $client->createException = null;

        $token = $identity->sign(42, $case['provider']);
        foreach(array('admin', 'reseller', 'client') as $role) {
            $client->liveZone = array('origin' => 'example.de', 'records' => array($case['provider']));
            $deleteResult = $service->delete(
                array('role' => $role, 'allowed_cache_ids' => array(42)),
                $token
            );
            dbsRecordServiceAssertSame(
                -42,
                $deleteResult['virtual_zone_id'],
                $type . ' verliert für ' . $role . ' die virtuelle Zone beim Delete.'
            );
        }
        dbsRecordServiceAssertSame(
            array('example.de', $case['provider']),
            $client->deleteCalls[count($client->deleteCalls) - 1],
            $type . ' Delete verwendet nicht den signierten und frisch bestätigten Tupel.'
        );

        $callsBeforeDeniedDelete = $factoryCalls;
        dbsRecordServiceExpectException(function() use ($service, $token) {
            $service->delete(array('allowed_cache_ids' => array()), $token);
        }, 'DbsRecordException', DbsRecordService::ERROR_ACCESS_DENIED);
        dbsRecordServiceAssertSame($callsBeforeDeniedDelete, $factoryCalls, 'Ein fremder Record erzeugt einen DBS-Client.');

        $tamperedToken = substr($token, 0, -1) . (substr($token, -1) === 'a' ? 'b' : 'a');
        dbsRecordServiceExpectException(function() use ($service, $tamperedToken) {
            $service->delete(array('allowed_cache_ids' => array(42)), $tamperedToken);
        }, 'DbsRecordException', DbsRecordService::ERROR_INVALID_IDENTIFIER);

        $client->liveZone = array('origin' => 'example.de', 'records' => array());
        $deleteCallsBeforeMissing = count($client->deleteCalls);
        dbsRecordServiceExpectException(function() use ($service, $token) {
            $service->delete(array('allowed_cache_ids' => array(42)), $token);
        }, 'DbsRecordException', DbsRecordService::ERROR_RECORD_NOT_FOUND);
        dbsRecordServiceAssertSame(
            $deleteCallsBeforeMissing,
            count($client->deleteCalls),
            'Ein nicht mehr vorhandener ' . $type . '-Record löst nameserverRRDelete aus.'
        );

        $client->liveZone = array('origin' => 'example.de', 'records' => array($case['provider']));
        $client->deleteException = new DbsClientException('safe failure', DbsClient::ERROR_REQUEST_REJECTED);
        dbsRecordServiceExpectException(function() use ($service, $token) {
            $service->delete(array('allowed_cache_ids' => array(42)), $token);
        }, 'DbsClientException', DbsClient::ERROR_REQUEST_REJECTED);
        $client->deleteException = null;
    }

    dbsRecordServiceAssertSame(
        '100',
        $cases['SRV']['provider']['aux'],
        'Die SRV-Priority liegt nicht ausschließlich in AUX.'
    );
    dbsRecordServiceAssertSame(
        '10 5060 target.example.net',
        $cases['SRV']['provider']['data'],
        'SRV Weight, Port und Target liegen nicht exakt in Data.'
    );
    dbsRecordServiceAssertTrue(
        strpos($cases['SRV']['provider']['data'], '100 ') !== 0,
        'Die SRV-Priority wurde zusätzlich in Data geschrieben.'
    );
    dbsRecordServiceAssertSame(
        $txtData,
        $cases['TXT']['provider']['data'],
        'TXT-Daten erhalten künstliche Quotes, Backslashes oder Doppel-Escaping.'
    );

    foreach(array(
        array('name' => '_service.tcp', 'data' => '10 5060 target.example.net', 'aux' => '100'),
        array('name' => 'service._tcp', 'data' => '10 5060 target.example.net', 'aux' => '100'),
        array('name' => '_service._tcp', 'data' => '65536 5060 target.example.net', 'aux' => '100'),
        array('name' => '_service._tcp', 'data' => '10 65536 target.example.net', 'aux' => '100'),
        array('name' => '_service._tcp', 'data' => '10 5060 invalid-target', 'aux' => '100'),
        array('name' => '_service._tcp', 'data' => '10 5060 target.example.net', 'aux' => '10junk'),
        array('name' => '_service._tcp', 'data' => '10 5060 target.example.net', 'aux' => '+10')
    ) as $invalidSrvFields) {
        $invalidSrv = $cases['SRV']['input'];
        $invalidSrv['name'] = $invalidSrvFields['name'];
        $invalidSrv['data'] = $invalidSrvFields['data'];
        $invalidSrv['aux'] = $invalidSrvFields['aux'];
        $callsBeforeInvalidSrv = $factoryCalls;
        dbsRecordServiceExpectException(function() use ($service, $invalidSrv) {
            $service->create(array('allowed_cache_ids' => array(42)), -42, $invalidSrv);
        }, 'DbsRecordException', DbsRecordService::ERROR_INVALID_RECORD);
        dbsRecordServiceAssertSame(
            $callsBeforeInvalidSrv,
            $factoryCalls,
            'Ungültige SRV-Komponenten lösen einen DBS-Aufruf aus.'
        );
    }

    $invalidTxt = $cases['TXT']['input'];
    $invalidTxt['data'] = "line one\nline two";
    dbsRecordServiceExpectException(function() use ($service, $invalidTxt) {
        $service->create(array('allowed_cache_ids' => array(42)), -42, $invalidTxt);
    }, 'DbsRecordException', DbsRecordService::ERROR_INVALID_RECORD);

    foreach(array('NS', 'SRV', 'TXT') as $type) {
        $providerRecord = $cases[$type]['provider'];
        $token = $identity->sign(42, $providerRecord);
        $differentRecord = $providerRecord;
        $differentRecord['ttl'] = (string)((int)$differentRecord['ttl'] + 1);
        $client->liveZone = array('origin' => 'example.de', 'records' => array($differentRecord));
        $deleteCallsBeforeMismatch = count($client->deleteCalls);
        dbsRecordServiceExpectException(function() use ($service, $token) {
            $service->delete(array('allowed_cache_ids' => array(42)), $token);
        }, 'DbsRecordException', DbsRecordService::ERROR_RECORD_NOT_FOUND);
        dbsRecordServiceAssertSame(
            $deleteCallsBeforeMismatch,
            count($client->deleteCalls),
            'Ein abweichender ' . $type . '-Provider-Tupel löst nameserverRRDelete aus.'
        );
    }

    $normalizedCnameToken = $identity->sign(42, $cases['CNAME']['provider']);
    $client->liveZone = array('origin' => 'EXAMPLE.DE.', 'records' => array(array(
        'name' => 'ALIAS.EXAMPLE.DE.',
        'type' => 'cname',
        'data' => 'TARGET.EXAMPLE.NET',
        'aux' => 0,
        'ttl' => 3600
    )));
    $service->delete(array('allowed_cache_ids' => array(42)), $normalizedCnameToken);
    dbsRecordServiceAssertSame(
        array('example.de', $cases['CNAME']['provider']),
        $client->deleteCalls[count($client->deleteCalls) - 1],
        'Eine äquivalente Providerdarstellung wird nicht über denselben HMAC-Tupel gelöscht.'
    );

    $client->liveZone = array('origin' => 'example.de', 'records' => array($cases['A']['provider']));
    $createCallsBeforeDuplicate = count($client->createCalls);
    dbsRecordServiceExpectException(function() use ($service, $cases) {
        $service->create(array('allowed_cache_ids' => array(42)), -42, $cases['A']['input']);
    }, 'DbsRecordException', DbsRecordService::ERROR_RECORD_ALREADY_EXISTS);
    dbsRecordServiceAssertSame(
        $createCallsBeforeDuplicate,
        count($client->createCalls),
        'Ein serieller Doppel-Create löst einen zweiten Provider-Write aus.'
    );

    $doubleDeleteToken = $identity->sign(42, $cases['A']['provider']);
    $deleteCallsBeforeDouble = count($client->deleteCalls);
    $service->delete(array('allowed_cache_ids' => array(42)), $doubleDeleteToken);
    dbsRecordServiceExpectException(function() use ($service, $doubleDeleteToken) {
        $service->delete(array('allowed_cache_ids' => array(42)), $doubleDeleteToken);
    }, 'DbsRecordException', DbsRecordService::ERROR_RECORD_NOT_FOUND);
    dbsRecordServiceAssertSame(
        $deleteCallsBeforeDouble + 1,
        count($client->deleteCalls),
        'Ein serieller Doppel-Delete löst einen zweiten Provider-Write aus.'
    );

    $client->liveZone = array('origin' => 'example.de', 'records' => array());
    $client->applyCreate = false;
    dbsRecordServiceExpectException(function() use ($service, $cases) {
        $service->create(array('allowed_cache_ids' => array(42)), -42, $cases['A']['input']);
    }, 'DbsRecordException', DbsRecordService::ERROR_VERIFICATION_FAILED);
    $client->applyCreate = true;

    $client->liveZone = array('origin' => 'example.de', 'records' => array($cases['A']['provider']));
    $client->applyDelete = false;
    dbsRecordServiceExpectException(function() use ($service, $doubleDeleteToken) {
        $service->delete(array('allowed_cache_ids' => array(42)), $doubleDeleteToken);
    }, 'DbsRecordException', DbsRecordService::ERROR_VERIFICATION_FAILED);
    $client->applyDelete = true;

    $existingProviderTxt = $cases['TXT']['provider'];
    $existingProviderTxt['data'] = str_repeat('x', 600);
    $existingProviderTxtToken = $identity->sign(42, $existingProviderTxt);
    $client->liveZone = array('origin' => 'example.de', 'records' => array($existingProviderTxt));
    $service->delete(array('allowed_cache_ids' => array(42)), $existingProviderTxtToken);
    dbsRecordServiceAssertSame(
        array('example.de', $existingProviderTxt),
        $client->deleteCalls[count($client->deleteCalls) - 1],
        'Ein repräsentierbarer bestehender Provider-TXT wird durch die Create-Grenze unlöschbar.'
    );

    $apexARecord = $cases['A']['input'];
    $apexARecord['name'] = '@';
    $client->liveZone = array('origin' => 'example.de', 'records' => array());
    $apexAResult = $service->create(
        array('allowed_cache_ids' => array(42)),
        -42,
        $apexARecord
    );
    dbsRecordServiceAssertSame(
        '',
        $apexAResult['record']['name'],
        'Der bestehende A-Apex-Host wird nicht weiterhin als leerer DBS-Owner übertragen.'
    );

    foreach(array('ALIAS', 'CAA', 'TLSA', 'OPENPGPKEY') as $unsupportedType) {
        $unsupportedRecord = $cases['A']['input'];
        $unsupportedRecord['type'] = $unsupportedType;
        dbsRecordServiceExpectException(function() use ($service, $unsupportedRecord) {
            $service->create(array('allowed_cache_ids' => array(42)), -42, $unsupportedRecord);
        }, 'DbsRecordException', DbsRecordService::ERROR_INVALID_RECORD);
    }

    $serviceSource = file_get_contents(__DIR__ . '/../src/dbsdns/lib/classes/DbsRecordService.inc.php');
    $pageSource = file_get_contents(__DIR__ . '/../src/dbsdns/lib/classes/DbsRecordPage.inc.php');
    $deleteSource = file_get_contents(__DIR__ . '/../src/dbsdns/record_delete.php');
    dbsRecordServiceAssertTrue(
        stripos($serviceSource . $pageSource . $deleteSource, 'INSERT INTO dns_rr') === false
        && stripos($serviceSource . $pageSource . $deleteSource, 'DELETE FROM dns_rr') === false
        && strpos($serviceSource . $pageSource . $deleteSource, 'datalogInsert') === false
        && strpos($serviceSource . $pageSource . $deleteSource, 'datalogDelete') === false,
        'Der DBS-Recordadapter schreibt unerwartet in dns_rr.'
    );
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'DBS-Record-Service für A, AAAA, CNAME, MX, NS, SRV und TXT erfolgreich geprüft.' . PHP_EOL;
