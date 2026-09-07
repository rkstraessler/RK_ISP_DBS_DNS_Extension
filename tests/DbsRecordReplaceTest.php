<?php

require_once __DIR__ . '/../src/dbsdns/lib/classes/DbsRecordService.inc.php';

class ReplaceTestAccess
{
    public function getAccessibleZoneByCacheId($context, $cacheId)
    {
        if(!isset($context['allowed']) || !in_array((int)$cacheId, $context['allowed'], true)) {
            return null;
        }

        return array('cache_id' => (int)$cacheId, 'normalized_domain' => 'example.de');
    }
}

class ReplaceTestClient
{
    public $records = array();
    public $operations = array();
    public $deleteFailure;
    public $createFailure;
    public $restoreFailure;
    public $createBeforeFailure = false;

    public function getZoneInfo($origin)
    {
        $this->operations[] = 'info';

        return array('origin' => $origin, 'records' => array_values($this->records));
    }

    public function deleteRecord($origin, $record)
    {
        $this->operations[] = 'delete';

        if($this->deleteFailure !== null) {
            throw $this->deleteFailure;
        }

        foreach($this->records as $index => $candidate) {
            if($candidate === $record) {
                unset($this->records[$index]);
                return;
            }
        }
    }

    public function createRecord($origin, $record)
    {
        $this->operations[] = 'create';

        if($this->createFailure !== null) {
            if($this->createBeforeFailure) {
                $this->records[] = $record;
            }

            throw $this->createFailure;
        }

        $this->records[] = $record;
    }

    public function restoreRecord($origin, $record)
    {
        $this->operations[] = 'restore';

        if($this->restoreFailure !== null) {
            throw $this->restoreFailure;
        }

        $this->records[] = $record;
    }
}

function replaceAssertSame($expected, $actual, $message)
{
    if($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function replaceExpect($callback, $code)
{
    try {
        $callback();
    } catch (DbsRecordException $exception) {
        replaceAssertSame($code, $exception->getCode(), 'Unerwarteter Replace-Fehlercode.');
        return;
    }

    throw new RuntimeException('Die erwartete Replace-Exception blieb aus.');
}

$testFailure = null;

try {
    $identity = new DbsRecordIdentity(str_repeat('r', 64));
    $client = new ReplaceTestClient();
    $factoryCalls = 0;
    $service = new DbsRecordService(new ReplaceTestAccess(), $identity, function() use ($client, &$factoryCalls) {
        $factoryCalls++;
        return $client;
    });
    $context = array('allowed' => array(42));
    $cases = array(
        'A' => array(
            'old' => array('name' => 'www', 'type' => 'A', 'data' => '192.0.2.1', 'aux' => '0', 'ttl' => '3600'),
            'input' => array('name' => 'web', 'type' => 'A', 'data' => '192.0.2.2', 'aux' => '0', 'ttl' => '7200', 'active' => 'Y'),
            'new' => array('name' => 'web', 'type' => 'A', 'data' => '192.0.2.2', 'aux' => '0', 'ttl' => '7200')
        ),
        'AAAA' => array(
            'old' => array('name' => 'ipv6', 'type' => 'AAAA', 'data' => '2001:db8::1', 'aux' => '0', 'ttl' => '3600'),
            'input' => array('name' => 'ipv6-new', 'type' => 'AAAA', 'data' => '2001:db8::2', 'aux' => '0', 'ttl' => '7200', 'active' => 'Y'),
            'new' => array('name' => 'ipv6-new', 'type' => 'AAAA', 'data' => '2001:db8::2', 'aux' => '0', 'ttl' => '7200')
        ),
        'CNAME' => array(
            'old' => array('name' => 'alias', 'type' => 'CNAME', 'data' => 'old.example.net.', 'aux' => '0', 'ttl' => '3600'),
            'input' => array('name' => 'alias-new', 'type' => 'CNAME', 'data' => 'new.example.net.', 'aux' => '0', 'ttl' => '7200', 'active' => 'Y'),
            'new' => array('name' => 'alias-new', 'type' => 'CNAME', 'data' => 'new.example.net.', 'aux' => '0', 'ttl' => '7200')
        ),
        'MX' => array(
            'old' => array('name' => 'example.de.', 'type' => 'MX', 'data' => 'mail1.example.net.', 'aux' => '10', 'ttl' => '3600'),
            'input' => array('name' => 'mail', 'type' => 'MX', 'data' => 'mail2.example.net.', 'aux' => '20', 'ttl' => '7200', 'active' => 'Y'),
            'new' => array('name' => 'mail.example.de.', 'type' => 'MX', 'data' => 'mail2.example.net.', 'aux' => '20', 'ttl' => '7200')
        ),
        'NS' => array(
            'old' => array('name' => '', 'type' => 'NS', 'data' => 'ns1.example.net', 'aux' => '0', 'ttl' => '86400'),
            'input' => array('name' => 'delegation', 'type' => 'NS', 'data' => 'ns2.example.net.', 'aux' => '0', 'ttl' => '7200', 'active' => 'Y'),
            'new' => array('name' => 'delegation', 'type' => 'NS', 'data' => 'ns2.example.net', 'aux' => '0', 'ttl' => '7200')
        ),
        'SRV' => array(
            'old' => array('name' => '_sip._tcp', 'type' => 'SRV', 'data' => '10 5060 sip1.example.net', 'aux' => '5', 'ttl' => '3600'),
            'input' => array('name' => '_sip._udp', 'type' => 'SRV', 'data' => '20 8443 sip2.example.net.', 'aux' => '10', 'ttl' => '7200', 'active' => 'Y'),
            'new' => array('name' => '_sip._udp', 'type' => 'SRV', 'data' => '20 8443 sip2.example.net', 'aux' => '10', 'ttl' => '7200')
        ),
        'TXT' => array(
            'old' => array('name' => '', 'type' => 'TXT', 'data' => 'old text', 'aux' => '0', 'ttl' => '3600'),
            'input' => array('name' => 'selector', 'type' => 'TXT', 'data' => 'new text value="quoted"', 'aux' => '0', 'ttl' => '7200', 'active' => 'Y'),
            'new' => array('name' => 'selector', 'type' => 'TXT', 'data' => 'new text value="quoted"', 'aux' => '0', 'ttl' => '7200')
        )
    );

    foreach($cases as $type => $case) {
        $client->records = array($case['old']);
        $client->operations = array();
        $token = $identity->sign(42, $case['old']);
        $loaded = $service->loadEditableRecord($context, $token);
        replaceAssertSame($case['old'], $loaded['record'], $type . ' wird nicht exakt für das Edit-Formular geladen.');

        $client->operations = array();
        $result = $service->replace($context, $token, $case['input']);
        replaceAssertSame(true, $result['changed'], $type . ' wird nicht als Änderung erkannt.');
        replaceAssertSame(array('info', 'delete', 'create', 'info'), $client->operations, $type . ' verwendet nicht Replace plus Live-Verifikation.');
        replaceAssertSame(array($case['new']), array_values($client->records), $type . ' endet nicht im erwarteten Providerzustand.');
    }

    $case = $cases['A'];
    $token = $identity->sign(42, $case['old']);
    $sameInput = array_merge($case['old'], array('active' => 'Y'));
    $client->records = array($case['old']);
    $client->operations = array();
    $noOp = $service->replace($context, $token, $sameInput);
    replaceAssertSame(false, $noOp['changed'], 'Identische Recorddaten werden nicht als No-op erkannt.');
    replaceAssertSame(array('info'), $client->operations, 'Ein Record-No-op führt einen RR-Write aus.');

    $factoryBeforeDenied = $factoryCalls;
    replaceExpect(function() use ($service, $token, $case) {
        $service->replace(array('allowed' => array()), $token, $case['input']);
    }, DbsRecordService::ERROR_ACCESS_DENIED);
    replaceAssertSame($factoryBeforeDenied, $factoryCalls, 'Eine fremde Zone erzeugt beim Edit einen DBS-Client.');

    $tampered = substr($token, 0, -1) . (substr($token, -1) === 'a' ? 'b' : 'a');
    replaceExpect(function() use ($service, $tampered, $case, $context) {
        $service->replace($context, $tampered, $case['input']);
    }, DbsRecordService::ERROR_INVALID_IDENTIFIER);

    $client->records = array();
    $client->operations = array();
    replaceExpect(function() use ($service, $token, $case, $context) {
        $service->replace($context, $token, $case['input']);
    }, DbsRecordService::ERROR_STALE_RECORD);
    replaceAssertSame(array('info'), $client->operations, 'Ein stale Record löst einen Write aus.');

    $manipulatedType = $case['input'];
    $manipulatedType['type'] = 'AAAA';
    $client->records = array($case['old']);
    $client->operations = array();
    replaceExpect(function() use ($service, $token, $manipulatedType, $context) {
        $service->replace($context, $token, $manipulatedType);
    }, DbsRecordService::ERROR_INVALID_RECORD);
    replaceAssertSame(array(), $client->operations, 'Eine Type-Manipulation erreicht den Provider.');

    $client->records = array($case['old']);
    $client->operations = array();
    $client->deleteFailure = new DbsClientException('safe', DbsClient::ERROR_REQUEST_REJECTED);
    try {
        $service->replace($context, $token, $case['input']);
        throw new RuntimeException('Delete failure blieb aus.');
    } catch (DbsClientException $exception) {
        replaceAssertSame(array('info', 'delete'), $client->operations, 'Nach Delete failure wird Create oder Restore ausgeführt.');
    }
    $client->deleteFailure = null;

    $client->records = array($case['old']);
    $client->operations = array();
    $client->createFailure = new DbsClientException('safe', DbsClient::ERROR_REQUEST_REJECTED);
    replaceExpect(function() use ($service, $token, $case, $context) {
        $service->replace($context, $token, $case['input']);
    }, DbsRecordService::ERROR_CREATE_FAILED_ROLLED_BACK);
    replaceAssertSame(array('info', 'delete', 'create', 'info', 'restore', 'info'), $client->operations, 'Create failure führt nicht durch Reconciliation und bestätigten Rollback.');
    replaceAssertSame(array($case['old']), array_values($client->records), 'Der alte Record wurde nach Create failure nicht wiederhergestellt.');

    $client->records = array($case['old']);
    $client->operations = array();
    $client->restoreFailure = new DbsClientException('safe', DbsClient::ERROR_REQUEST_REJECTED);
    replaceExpect(function() use ($service, $token, $case, $context) {
        $service->replace($context, $token, $case['input']);
    }, DbsRecordService::ERROR_ROLLBACK_FAILED);
    replaceAssertSame(array('info', 'delete', 'create', 'info', 'restore', 'info'), $client->operations, 'Rollback failure lädt den tatsächlichen Providerzustand nicht neu.');
    replaceAssertSame(array(), array_values($client->records), 'Der Rollback-failure-Test besitzt einen unerwarteten Providerzustand.');
    $client->createFailure = null;
    $client->restoreFailure = null;

    $client->records = array($case['old']);
    $client->operations = array();
    $client->createBeforeFailure = true;
    $client->createFailure = new DbsClientException('ambiguous timeout', DbsClient::ERROR_UNREACHABLE);
    replaceExpect(function() use ($service, $token, $case, $context) {
        $service->replace($context, $token, $case['input']);
    }, DbsRecordService::ERROR_ROLLBACK_FAILED);
    replaceAssertSame(array('info', 'delete', 'create', 'info'), $client->operations, 'Ein ambiger Create-Timeout löst einen blinden Restore aus.');
    replaceAssertSame(array($case['new']), array_values($client->records), 'Die Reconciliation verliert den tatsächlich angelegten neuen Record.');
    $client->createBeforeFailure = false;
    $client->createFailure = null;

    $longTxt = array('name' => '', 'type' => 'TXT', 'data' => str_repeat('x', 600), 'aux' => '0', 'ttl' => '3600');
    $longTxtToken = $identity->sign(42, $longTxt);
    $client->records = array($longTxt);
    $client->operations = array();
    $longTxtNoOp = $service->replace(
        $context,
        $longTxtToken,
        array_merge($longTxt, array('active' => 'Y'))
    );
    replaceAssertSame(false, $longTxtNoOp['changed'], 'Ein unveränderter langer Provider-TXT ist nicht speicherbar.');
    replaceAssertSame(array('info'), $client->operations, 'Ein unveränderter langer Provider-TXT löst einen Write aus.');

    $lowTtl = array('name' => 'legacy', 'type' => 'A', 'data' => '192.0.2.10', 'aux' => '0', 'ttl' => '30');
    $lowTtlToken = $identity->sign(42, $lowTtl);
    $client->records = array($lowTtl);
    $client->operations = array();
    $lowTtlNoOp = $service->replace($context, $lowTtlToken, array_merge($lowTtl, array('active' => 'Y')));
    replaceAssertSame(false, $lowTtlNoOp['changed'], 'Ein unveränderter Providerrecord mit Legacy-TTL ist nicht speicherbar.');
    replaceAssertSame(array('info'), $client->operations, 'Ein Legacy-TTL-No-op löst einen Write aus.');

    $longTxtInput = array('name' => '', 'type' => 'TXT', 'data' => 'short replacement', 'aux' => '0', 'ttl' => '3600', 'active' => 'Y');
    $client->records = array($longTxt);
    $client->operations = array();
    $client->createFailure = new DbsClientException('safe', DbsClient::ERROR_REQUEST_REJECTED);
    replaceExpect(function() use ($service, $longTxtToken, $longTxtInput, $context) {
        $service->replace($context, $longTxtToken, $longTxtInput);
    }, DbsRecordService::ERROR_CREATE_FAILED_ROLLED_BACK);
    replaceAssertSame(array($longTxt), array_values($client->records), 'Ein bestehender langer TXT kann beim Rollback nicht exakt restauriert werden.');
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'DBS-Record-Edit für alle freigegebenen Typen, Stale-Schutz, No-op und Rollback erfolgreich geprüft.' . PHP_EOL;
