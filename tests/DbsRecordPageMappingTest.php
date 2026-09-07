<?php

class tform_actions
{
}

require_once __DIR__ . '/../src/dbsdns/lib/classes/DbsRecordPage.inc.php';

function dbsRecordPageMappingAssertSame($expected, $actual, $message)
{
    if($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function dbsRecordPageMappingPrepare($type, $record)
{
    $page = new DbsRecordPage($type, array());
    $method = new ReflectionMethod('DbsRecordPage', 'prepareRecordForValidation');
    if(PHP_VERSION_ID < 80100) {
        $method->setAccessible(true);
    }

    return $method->invoke($page, $record);
}

function dbsRecordPageMappingForm($type)
{
    $_REQUEST = array('type' => $type);
    $form = array();
    include __DIR__ . '/../src/dbsdns/form/record.tform.php';

    return $form;
}

function dbsRecordPageMappingProviderForm($type, $record, $origin = 'example.de')
{
    $page = new DbsRecordPage($type, array(), 'signed-token');
    $cacheId = new ReflectionProperty('DbsRecordPage', 'cacheId');
    $method = new ReflectionMethod('DbsRecordPage', 'mapProviderRecordToForm');

    if(PHP_VERSION_ID < 80100) {
        $cacheId->setAccessible(true);
        $method->setAccessible(true);
    }

    $cacheId->setValue($page, 42);

    return $method->invoke($page, $record, $origin);
}

$testFailure = null;

try {
    $srv = dbsRecordPageMappingPrepare('SRV', array(
        'name' => '_service._tcp',
        'data' => '999 999 attacker.example',
        'weight' => '005',
        'port' => '0443',
        'target' => ' service.example.tld. ',
        'aux' => '10',
        'ttl' => '3600'
    ));
    dbsRecordPageMappingAssertSame(
        '5 443 service.example.tld.',
        $srv['data'],
        'Die SRV-Formfelder werden nicht in Weight/Port/Target abgebildet.'
    );

    $prefilledSrv = dbsRecordPageMappingProviderForm('SRV', array(
        'name' => '_sip._tcp',
        'type' => 'SRV',
        'data' => '10 5060 sip.example.net',
        'aux' => '5',
        'ttl' => '3600'
    ));
    dbsRecordPageMappingAssertSame('10', $prefilledSrv['weight'], 'SRV Weight wird nicht vorausgefüllt.');
    dbsRecordPageMappingAssertSame('5060', $prefilledSrv['port'], 'SRV Port wird nicht vorausgefüllt.');
    dbsRecordPageMappingAssertSame('sip.example.net', $prefilledSrv['target'], 'SRV Target wird nicht vorausgefüllt.');
    dbsRecordPageMappingAssertSame('5', $prefilledSrv['aux'], 'SRV Priority wird nicht vorausgefüllt.');

    foreach(array('A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT') as $type) {
        $record = array(
            'name' => $type === 'MX' ? 'example.de.' : 'owner',
            'type' => $type,
            'data' => $type === 'A' ? '192.0.2.1' : ($type === 'AAAA' ? '2001:db8::1' : 'value.example.net.'),
            'aux' => $type === 'MX' ? '10' : '0',
            'ttl' => '3600'
        );

        if($type === 'NS') {
            $record['data'] = 'ns.example.net';
        } elseif($type === 'TXT') {
            $record['data'] = 'raw txt value';
        }

        $prefilled = dbsRecordPageMappingProviderForm($type, $record);
        $expectedName = $type === 'MX' ? '' : $record['name'];
        $expectedData = in_array($type, array('CNAME', 'MX'), true)
            ? substr($record['data'], 0, -1)
            : $record['data'];
        dbsRecordPageMappingAssertSame($expectedName, $prefilled['name'], $type . ' Name wird nicht vorausgefüllt.');
        dbsRecordPageMappingAssertSame($expectedData, $prefilled['data'], $type . ' Data wird nicht vorausgefüllt.');
        dbsRecordPageMappingAssertSame($record['ttl'], $prefilled['ttl'], $type . ' TTL wird nicht vorausgefüllt.');
    }

    $invalidSrv = dbsRecordPageMappingPrepare('SRV', array(
        'data' => 'manipulated',
        'weight' => 'invalid',
        'port' => '70000',
        'target' => 'service.example.tld'
    ));
    dbsRecordPageMappingAssertSame(
        'invalid 70000 service.example.tld',
        $invalidSrv['data'],
        'Ungültige native SRV-Komponenten werden vor der Servervalidierung verdeckt.'
    );
    $recordTemplate = file_get_contents(__DIR__ . '/../src/dbsdns/templates/record_edit.htm');
    $de = array();
    include __DIR__ . '/../src/dbsdns/lib/lang/de_dbsdns_record.lng';
    $de = $wb;
    unset($wb);
    $en = array();
    include __DIR__ . '/../src/dbsdns/lib/lang/en_dbsdns_record.lng';
    $en = $wb;
    unset($wb);
    dbsRecordPageMappingAssertSame(
        array(
            'IPv4-Adresse',
            'IPv6-Adresse',
            'Ziel-Hostname',
            'Mailserver',
            'Nameserver',
            'TXT-Wert',
            'Gewichtung',
            'Port',
            'Priorität'
        ),
        array(
            $de['ipv4_txt'],
            $de['ipv6_txt'],
            $de['target_hostname_txt'],
            $de['mailserver_txt'],
            $de['nameserver_txt'],
            $de['txt_value_txt'],
            $de['weight_txt'],
            $de['port_txt'],
            $de['aux_txt']
        ),
        'Die deutschen Recordtyp-Labels sind nicht fachlich eindeutig.'
    );
    dbsRecordPageMappingAssertSame(
        true,
        strpos($de['cname_target_hint_txt'], 'example.com') !== false
            && strpos($de['mx_target_hint_txt'], 'mail.example.com') !== false
            && strpos($de['ns_target_hint_txt'], 'ns1.example.com') !== false
            && $de['srv_target_hint_txt'] !== ''
            && strpos($de['apex_name_hint_txt'], '@') !== false
            && strpos($de['srv_name_hint_txt'], '_service._tcp') !== false
            && stripos(
                $de['cname_target_hint_txt'] . $de['mx_target_hint_txt']
                    . $de['ns_target_hint_txt'] . $de['srv_target_hint_txt'],
                'abschließender Punkt'
            ) === false,
        'FQDN-, Apex- oder SRV-Hinweise fehlen beziehungsweise behaupten eine falsche Punktpflicht.'
    );
    $nameGroupStart = strpos($recordTemplate, '<label for="name"');
    $nameGroupEnd = strpos($recordTemplate, '<tmpl_if name="show_srv">');
    $nameGroup = substr($recordTemplate, $nameGroupStart, $nameGroupEnd - $nameGroupStart);
    dbsRecordPageMappingAssertSame(
        true,
        strpos($nameGroup, 'class="col-sm-9"') !== false
            && strpos($nameGroup, 'id="dbsdns-name-hint"') !== false,
        'Der Name-/Apex-Hinweis liegt außerhalb des Formularrasters.'
    );
    dbsRecordPageMappingAssertSame(
        true,
        $de['btn_save_txt'] === 'Speichern'
            && $de['btn_cancel_txt'] === 'Abbrechen'
            && $en['btn_save_txt'] === 'Save'
            && $en['btn_cancel_txt'] === 'Cancel'
            && strpos($recordTemplate, 'name="record_token"') !== false
            && strpos($recordTemplate, 'data-submit-form="pageForm"') !== false
            && strpos($recordTemplate, 'data-dbsdns-mutation="record-save"') !== false
            && strpos($recordTemplate, 'disabled="disabled"') !== false
            && strpos($recordTemplate, 'name="active" value="Y"') !== false
            && strpos($recordTemplate, 'btn_save_txt') !== false
            && strpos($recordTemplate, 'btn_cancel_txt') !== false,
        'Edit-HMAC oder lokalisierte Speichern-/Abbrechen-Buttons fehlen.'
    );

    $quotedTxt = dbsRecordPageMappingPrepare('TXT', array(
        'data' => '"v=spf1 include:_spf.example -all"'
    ));
    dbsRecordPageMappingAssertSame(
        '"v=spf1 include:_spf.example -all"',
        $quotedTxt['data'],
        'Absichtlich äußere TXT-Quotes werden verändert.'
    );

    $rawTxtData = 'path=\\raw value="embedded"';
    $rawTxt = dbsRecordPageMappingPrepare('TXT', array('data' => $rawTxtData));
    dbsRecordPageMappingAssertSame(
        $rawTxtData,
        $rawTxt['data'],
        'TXT-Backslashes oder eingebettete Quotes werden verändert oder doppelt escaped.'
    );

    $ns = dbsRecordPageMappingPrepare('NS', array(
        'name' => '@',
        'data' => 'ns.example.tld.',
        'aux' => '999'
    ));
    dbsRecordPageMappingAssertSame(
        'ns.example.tld.',
        $ns['data'],
        'Der NS-Eingabewert wird vor der zentralen DBS-Normalisierung verändert.'
    );
    dbsRecordPageMappingAssertSame(
        '@',
        $ns['name'],
        'Der NS-Apexwert wird vor der zentralen Normalisierung verändert.'
    );

    $apexTxt = dbsRecordPageMappingPrepare('TXT', array(
        'name' => '@',
        'data' => 'raw txt value'
    ));
    dbsRecordPageMappingAssertSame(
        '@',
        $apexTxt['name'],
        'Der TXT-Apexwert wird vor der zentralen Normalisierung verändert.'
    );

    $pageSource = file_get_contents(__DIR__ . '/../src/dbsdns/lib/classes/DbsRecordPage.inc.php');
    $aFields = dbsRecordPageMappingForm('A')['tabs']['dns']['fields'];
    $mxFields = dbsRecordPageMappingForm('MX')['tabs']['dns']['fields'];
    $srvFields = dbsRecordPageMappingForm('SRV')['tabs']['dns']['fields'];
    $txtFields = dbsRecordPageMappingForm('TXT')['tabs']['dns']['fields'];

    dbsRecordPageMappingAssertSame(
        true,
        !isset($aFields['aux'])
            && !isset($aFields['target'])
            && !isset($aFields['weight'])
            && !isset($aFields['port'])
            && isset($mxFields['data'], $mxFields['aux'])
            && !isset($mxFields['target'])
            && !isset($mxFields['weight'])
            && !isset($mxFields['port'])
            && isset($srvFields['target'], $srvFields['weight'], $srvFields['port'], $srvFields['aux'])
            && !isset($srvFields['data'])
            && isset($txtFields['data'])
            && !isset($txtFields['aux'])
            && !isset($txtFields['target']),
        'Die TForm-Felder werden nicht exakt für den gewählten Recordtyp aufgebaut.'
    );
    dbsRecordPageMappingAssertSame(
        true,
        strpos($pageSource, "\$this->recordType === 'SRV'") !== false
            && strpos($pageSource, "isset(\$validatedRecord['aux'])") !== false,
        'Die SRV-Priority wird nicht aus dem validierten TForm-Wert übernommen.'
    );
    dbsRecordPageMappingAssertSame(
        true,
        strpos($pageSource, "csrf_token_check('POST')")
            < strpos($pageSource, 'validateWithTform($rawRecord)')
            && strpos($pageSource, 'tform->encode(') === false,
        'Der Create-Pfad prüft CSRF nicht genau einmal vor der TForm-Validierung.'
    );
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'DBS-Record-Formmapping für NS, SRV und TXT erfolgreich geprüft.' . PHP_EOL;
