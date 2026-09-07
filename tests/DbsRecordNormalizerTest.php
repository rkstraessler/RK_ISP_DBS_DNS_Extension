<?php

require_once __DIR__ . '/../src/dbsdns/lib/classes/DbsRecordIdentity.inc.php';

function normalizerAssertSame($expected, $actual, $message)
{
    if($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function normalizerAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

function normalizerExpectReason($callback, $reason)
{
    try {
        $callback();
    } catch (DbsRecordNormalizationException $exception) {
        normalizerAssertSame($reason, $exception->getReason(), 'Unerwarteter Normalisierungsfehler.');
        return;
    }

    throw new RuntimeException('Die erwartete Normalisierungs-Exception blieb aus.');
}

$testFailure = null;

try {
    $normalizer = new DbsRecordNormalizer();
    $identity = new DbsRecordIdentity(str_repeat('n', 64), $normalizer);
    $origin = 'example.de';
    $forms = array(
        'A' => array(
            'input' => array('name' => '@', 'type' => 'A', 'data' => '192.0.2.1', 'aux' => '0', 'ttl' => '3600', 'active' => 'Y'),
            'provider' => array('name' => '', 'type' => 'A', 'data' => '192.0.2.1', 'aux' => '0', 'ttl' => '3600')
        ),
        'AAAA' => array(
            'input' => array('name' => 'IPV6.EXAMPLE.DE.', 'type' => 'aaaa', 'data' => '2001:DB8::1', 'aux' => '0', 'ttl' => '3600', 'active' => 'Y'),
            'provider' => array('name' => 'ipv6', 'type' => 'AAAA', 'data' => '2001:db8::1', 'aux' => '0', 'ttl' => '3600')
        ),
        'CNAME' => array(
            'input' => array('name' => 'Alias.Example.De.', 'type' => 'CNAME', 'data' => 'TARGET.EXAMPLE.NET', 'aux' => '0', 'ttl' => '3600', 'active' => 'Y'),
            'provider' => array('name' => 'alias', 'type' => 'CNAME', 'data' => 'target.example.net.', 'aux' => '0', 'ttl' => '3600')
        ),
        'MX' => array(
            'input' => array('name' => '', 'type' => 'MX', 'data' => 'MAIL.EXAMPLE.NET', 'aux' => '10', 'ttl' => '3600', 'active' => 'Y'),
            'provider' => array('name' => 'example.de.', 'type' => 'MX', 'data' => 'mail.example.net.', 'aux' => '10', 'ttl' => '3600')
        ),
        'NS' => array(
            'input' => array('name' => '@', 'type' => 'NS', 'data' => 'NS1.EXAMPLE.NET.', 'aux' => '0', 'ttl' => '86400', 'active' => 'Y'),
            'provider' => array('name' => '', 'type' => 'NS', 'data' => 'ns1.example.net', 'aux' => '0', 'ttl' => '86400')
        ),
        'SRV' => array(
            'input' => array('name' => '_SIP._TCP.EXAMPLE.DE.', 'type' => 'SRV', 'data' => '10 5060 SIP.EXAMPLE.NET.', 'aux' => '5', 'ttl' => '3600', 'active' => 'Y'),
            'provider' => array('name' => '_sip._tcp', 'type' => 'SRV', 'data' => '10 5060 sip.example.net', 'aux' => '5', 'ttl' => '3600')
        ),
        'TXT' => array(
            'input' => array('name' => '@', 'type' => 'TXT', 'data' => 'raw value="quoted" path=\\raw', 'aux' => '0', 'ttl' => '3600', 'active' => 'Y'),
            'provider' => array('name' => '', 'type' => 'TXT', 'data' => 'raw value="quoted" path=\\raw', 'aux' => '0', 'ttl' => '3600')
        )
    );

    foreach($forms as $type => $case) {
        normalizerAssertSame(
            $case['provider'],
            $normalizer->normalizeFormToProviderRecord($case['input'], $origin),
            $type . ' wird nicht ins bestätigte DBS-Providerformat normalisiert.'
        );
    }

    $providerVariant = array(
        'name' => 'ALIAS.EXAMPLE.DE.',
        'type' => 'cname',
        'data' => 'TARGET.EXAMPLE.NET',
        'aux' => 0,
        'ttl' => 3600
    );
    $canonicalVariant = $normalizer->normalizeProviderRecord($providerVariant, 'EXAMPLE.DE.');
    normalizerAssertSame($forms['CNAME']['provider'], $canonicalVariant, 'Provider-Casing, Owner oder Zahlentypen bleiben uneinheitlich.');
    normalizerAssertSame(
        $identity->sign(42, $forms['CNAME']['provider']),
        $identity->sign(42, $canonicalVariant),
        'Äquivalente Providerdarstellungen erzeugen unterschiedliche HMAC-Identitäten.'
    );
    normalizerAssertSame(
        '2001:db8::1',
        $normalizer->normalizeProviderRecord(array(
            'name' => 'ipv6.example.de.',
            'type' => 'AAAA',
            'data' => '2001:0DB8:0000:0000:0000:0000:0000:0001',
            'aux' => 0,
            'ttl' => 3600
        ), $origin)['data'],
        'Äquivalente IPv6-Providerdarstellungen werden nicht kanonisiert.'
    );

    $cnameForm = $normalizer->normalizeProviderToForm($forms['CNAME']['provider'], $origin);
    normalizerAssertSame('target.example.net', $cnameForm['data'], 'CNAME zeigt Providerpunkte unnötig im Formular.');
    $mxForm = $normalizer->normalizeProviderToForm($forms['MX']['provider'], $origin);
    normalizerAssertSame('', $mxForm['name'], 'MX-Apex wird nicht als leerer Formular-Owner rekonstruiert.');
    normalizerAssertSame('mail.example.net', $mxForm['data'], 'MX zeigt Providerpunkte unnötig im Formular.');
    $srvForm = $normalizer->normalizeProviderToForm($forms['SRV']['provider'], $origin);
    normalizerAssertSame('10', $srvForm['weight'], 'SRV Weight wird nicht rekonstruiert.');
    normalizerAssertSame('5060', $srvForm['port'], 'SRV Port wird nicht rekonstruiert.');
    normalizerAssertSame('sip.example.net', $srvForm['target'], 'SRV Target wird nicht rekonstruiert.');
    normalizerAssertSame('5', $srvForm['aux'], 'SRV Priority wird nicht aus AUX rekonstruiert.');

    foreach(array(
        'test.txt',
        'v=spf1 include:_spf.example -all',
        'text with spaces',
        '"intentional outer quotes"',
        'path=\\raw value="embedded"'
    ) as $txtValue) {
        $txt = $forms['TXT']['input'];
        $txt['data'] = $txtValue;
        normalizerAssertSame(
            $txtValue,
            $normalizer->normalizeFormToProviderRecord($txt, $origin)['data'],
            'TXT-Inhalt wird gequotet, escaped oder als Hostname behandelt.'
        );
    }

    normalizerExpectReason(function() use ($normalizer, $forms, $origin) {
        $record = $forms['SRV']['input'];
        $record['name'] = '';
        $normalizer->normalizeFormToProviderRecord($record, $origin);
    }, 'srv_name');
    normalizerExpectReason(function() use ($normalizer, $forms, $origin) {
        $record = $forms['CNAME']['input'];
        $record['data'] = 'target.example.net..';
        $normalizer->normalizeFormToProviderRecord($record, $origin);
    }, 'cname_target');

    $expectedFields = array(
        'A' => array('name', 'data', 'ttl', 'active'),
        'AAAA' => array('name', 'data', 'ttl', 'active'),
        'CNAME' => array('name', 'data', 'ttl', 'active'),
        'MX' => array('name', 'data', 'aux', 'ttl', 'active'),
        'NS' => array('name', 'data', 'ttl', 'active'),
        'SRV' => array('name', 'target', 'weight', 'port', 'aux', 'ttl', 'active'),
        'TXT' => array('name', 'data', 'ttl', 'active')
    );

    foreach($expectedFields as $type => $fields) {
        normalizerAssertSame($fields, DbsRecordCapabilities::formProfile($type)['fields'], $type . ' besitzt kein vollständiges Edit-Mapping.');
        normalizerAssertTrue(
            DbsRecordCapabilities::canCreate($type)
                && DbsRecordCapabilities::canEdit($type)
                && DbsRecordCapabilities::canDelete($type),
            $type . ' erhält trotz unvollständiger Capability eine Schreibaktion.'
        );
    }
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Zentrale DBS-Record-Normalisierung und Capability-Mappings erfolgreich geprüft.' . PHP_EOL;
