<?php

require_once __DIR__ . '/../src/dbsdns/lib/classes/DbsRecordListRenderer.inc.php';

function dbsRecordAdapterAssertSame($expected, $actual, $message)
{
    if($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

$testFailure = null;

try {
    $identity = new DbsRecordIdentity(str_repeat('b', 64));
    $renderer = new DbsRecordListRenderer($identity);
    $records = $renderer->normalizeRecords(array(
        array(
            'type' => 'mx',
            'name' => 'example.de.',
            'data' => 'mail.example.',
            'aux' => '10',
            'ttl' => '3600'
        )
    ), -42);

    dbsRecordAdapterAssertSame(1, count($records), 'Der DBS-Recordadapter verwirft einen gültigen Record.');
    dbsRecordAdapterAssertSame('Y', $records[0]['active'], 'Ein gelieferter DBS-Record wird in der nativen Tabelle nicht als vorhanden dargestellt.');
    dbsRecordAdapterAssertSame('MX', $records[0]['type'], 'Der Record-Typ wird nicht normalisiert.');
    dbsRecordAdapterAssertSame('example.de.', $records[0]['name'], 'Der Record-Name wird verändert.');
    dbsRecordAdapterAssertSame('mail.example.', $records[0]['data'], 'Die Record-Daten werden verändert.');
    dbsRecordAdapterAssertSame('10', $records[0]['aux'], 'Die Priorität wird nicht übernommen.');
    dbsRecordAdapterAssertSame('3600', $records[0]['ttl'], 'Die TTL wird nicht übernommen.');
    dbsRecordAdapterAssertSame(42, $records[0]['zone'], 'Der Record ist nicht an die DBS-Cache-Zone gebunden.');
    dbsRecordAdapterAssertSame(1, $records[0]['can_delete'], 'Ein unterstützter Record erhält keine Delete-Aktion.');
    dbsRecordAdapterAssertSame(1, $records[0]['can_edit'], 'Ein unterstützter Record erhält keine Edit-Aktion.');
    $verifiedMxIdentity = $identity->verify($records[0]['id']);
    dbsRecordAdapterAssertSame('MX', $verifiedMxIdentity['record']['type'], 'Der MX-Record erhält keinen sicheren Identifier.');
    dbsRecordAdapterAssertSame('10', $verifiedMxIdentity['record']['aux'], 'Die MX-Priorität fehlt in der HMAC-Identität.');

    $aRecords = $renderer->normalizeRecords(array(
        array(
            'type' => 'A',
            'name' => 'www',
            'data' => '94.130.231.186',
            'aux' => '0',
            'ttl' => '3600'
        )
    ), -42);
    $verifiedIdentity = $identity->verify($aRecords[0]['id']);

    dbsRecordAdapterAssertSame(42, $verifiedIdentity['cache_id'], 'Die signierte Record-ID ist nicht an die DBS-Zone gebunden.');
    dbsRecordAdapterAssertSame('A', $verifiedIdentity['record']['type'], 'Die signierte Record-ID enthält nicht ausschließlich einen A-Record.');
    dbsRecordAdapterAssertSame('www', $verifiedIdentity['record']['name'], 'Der signierte Record-Tupel verliert den Hostnamen.');

    foreach(array(
        array('type' => 'AAAA', 'name' => 'ipv6', 'data' => '2001:db8::1', 'aux' => '0', 'ttl' => '3600'),
        array('type' => 'CNAME', 'name' => 'alias', 'data' => 'target.example.tld.', 'aux' => '0', 'ttl' => '3600')
    ) as $editableRecord) {
        $editable = $renderer->normalizeRecords(array($editableRecord), -42);
        dbsRecordAdapterAssertSame(1, $editable[0]['can_edit'], $editableRecord['type'] . ' erhält keinen Edit-Stift.');
    }

    $newRecordTypes = array(
        'NS' => array(
            'type' => 'NS',
            'name' => '',
            'data' => 'ns.example.tld',
            'aux' => '0',
            'ttl' => '86400'
        ),
        'SRV' => array(
            'type' => 'SRV',
            'name' => '_sip._tcp',
            'data' => '10 5060 sip.example.tld',
            'aux' => '5',
            'ttl' => '3600'
        ),
        'TXT' => array(
            'type' => 'TXT',
            'name' => '',
            'data' => 'v=spf1 include:_spf.example -all path=\\raw value="quoted"',
            'aux' => '0',
            'ttl' => '3600'
        )
    );

    foreach($newRecordTypes as $type => $record) {
        $normalized = $renderer->normalizeRecords(array($record), -42);
        $verified = $identity->verify($normalized[0]['id']);
        $expectedRecord = array(
            'name' => $record['name'],
            'type' => $record['type'],
            'data' => $record['data'],
            'aux' => $record['aux'],
            'ttl' => $record['ttl']
        );
        dbsRecordAdapterAssertSame(
            $expectedRecord,
            $verified['record'],
            $type . ' verliert im HMAC-Identifier Teile des exakten Provider-Tupels.'
        );
    }

    dbsRecordAdapterAssertSame(
        '5',
        $identity->verify($renderer->normalizeRecords(array($newRecordTypes['SRV']), -42)[0]['id'])['record']['aux'],
        'Die SRV-Priority fehlt in AUX der HMAC-Identität.'
    );
    dbsRecordAdapterAssertSame(
        '10 5060 sip.example.tld',
        $identity->verify($renderer->normalizeRecords(array($newRecordTypes['SRV']), -42)[0]['id'])['record']['data'],
        'SRV Weight, Port oder Target fehlen in Data der HMAC-Identität.'
    );

    $unicodeTxt = $newRecordTypes['TXT'];
    $unicodeTxt['data'] = str_repeat('😀', 128);
    $unicodeRecords = $renderer->normalizeRecords(array($unicodeTxt), -42);
    dbsRecordAdapterAssertSame(
        $unicodeTxt['data'],
        $identity->verify($unicodeRecords[0]['id'])['record']['data'],
        'Ein gültiger UTF-8-TXT-Record erzeugt keinen verifizierbaren Delete-Identifier.'
    );

    $providerTxt = $newRecordTypes['TXT'];
    $providerTxt['data'] = str_repeat('x', 600);
    $providerRecords = $renderer->normalizeRecords(array($providerTxt), -42);
    dbsRecordAdapterAssertSame(
        $providerTxt['data'],
        $identity->verify($providerRecords[0]['id'])['record']['data'],
        'Ein repräsentierbarer bestehender Provider-TXT wird durch die Create-Grenze unlöschbar.'
    );

    foreach(array('ALIAS', 'CAA', 'TLSA', 'OPENPGPKEY') as $position => $unsupportedType) {
        $unsupportedRecords = $renderer->normalizeRecords(array(array(
            'type' => $unsupportedType,
            'name' => 'owner',
            'data' => 'opaque provider data',
            'aux' => '0',
            'ttl' => '3600'
        )), -42);
        dbsRecordAdapterAssertSame(
            '',
            $unsupportedRecords[0]['id'],
            $unsupportedType . ' erhält unerwartet eine löschbare HMAC-ID.'
        );
        dbsRecordAdapterAssertSame(
            0,
            $unsupportedRecords[0]['can_delete'],
            $unsupportedType . ' erhält unerwartet eine Delete-Aktion.'
        );
        dbsRecordAdapterAssertSame(
            0,
            $unsupportedRecords[0]['can_edit'],
            $unsupportedType . ' erhält unerwartet eine Edit-Aktion.'
        );
    }
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'DBS-Recordadapter erfolgreich geprüft.' . PHP_EOL;
