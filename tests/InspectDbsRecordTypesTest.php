<?php

ob_start();
require_once __DIR__ . '/../scripts/inspect-dbs-record-types.php';
$includeOutput = ob_get_clean();

class InspectDbsRecordTypesFakeClient
{
    public $listCalls = 0;
    public $zoneCalls = array();
    public $domains = array();
    public $zones = array();

    public function listAllDomains()
    {
        $this->listCalls++;

        return $this->domains;
    }

    public function getZoneInfo($origin)
    {
        $this->zoneCalls[] = $origin;

        if($origin === 'broken.example') {
            throw new RuntimeException('sensitive-broken.example darf nicht erscheinen');
        }

        return $this->zones[$origin];
    }
}

function inspectDbsAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

function inspectDbsRecord($name, $type, $data, $aux, $ttl)
{
    return array(
        'name' => $name,
        'type' => $type,
        'data' => $data,
        'aux' => $aux,
        'ttl' => $ttl
    );
}

$testFailure = null;

try {
    inspectDbsAssertTrue($includeOutput === '', 'Das Einbinden des Diagnosewerkzeugs erzeugt unerwartete Ausgabe.');

    $client = new InspectDbsRecordTypesFakeClient();
    $client->domains = array(
        array('origin' => 'first.example'),
        array('origin' => 'FIRST.EXAMPLE.'),
        array('origin' => 'broken.example'),
        array('origin' => 'coverage.example'),
        array('origin' => 'must-not-be-requested.example')
    );
    $txtData = '"sensitive verification\\token"';
    $client->zones['first.example'] = array('records' => array(
        inspectDbsRecord('_secret-owner', 'TXT', $txtData, '0', '3600'),
        inspectDbsRecord('www', 'A', '192.0.2.1', '0', '3600')
    ));
    $client->zones['coverage.example'] = array('records' => array(
        inspectDbsRecord('', 'ALIAS', 'target.sensitive.example.', '0', '3600'),
        inspectDbsRecord('', 'CAA', '0 issue "authority.sensitive"', '0', '3600'),
        inspectDbsRecord('', 'NS', 'ns1.sensitive.example.', '0', '7200'),
        inspectDbsRecord('_sip._tcp', 'SRV', '20 5060 sip.sensitive.example.', '10', '3600'),
        inspectDbsRecord('_443._tcp', 'TLSA', '3 1 1 ABCDEF012345', '0', '3600'),
        inspectDbsRecord('_second-owner', 'TXT', 'another-sensitive-value', '0', '3600'),
        inspectDbsRecord('_third-owner', 'TXT', 'third-sensitive-value', '0', '3600'),
        inspectDbsRecord('_fourth-owner', 'TXT', 'fourth-sensitive-value', '0', '3600')
    ));

    $sleepCalls = array();
    $report = dbsInspectorScan($client, 3, 250, function($milliseconds) use (&$sleepCalls) {
        $sleepCalls[] = $milliseconds;
    });

    inspectDbsAssertTrue($client->listCalls === 1, 'listAllDomains() wird nicht genau einmal aufgerufen.');
    inspectDbsAssertTrue(
        $client->zoneCalls === array('first.example', 'broken.example', 'coverage.example'),
        'Zonen werden doppelt, nach dem Early Stop oder in falscher Reihenfolge abgefragt.'
    );
    inspectDbsAssertTrue($sleepCalls === array(250, 250), 'Die Pause liegt nicht genau zwischen Zonenrequests.');
    inspectDbsAssertTrue($report['domain_requests'] === 3, 'Die Anzahl der Zonenrequests ist falsch.');
    inspectDbsAssertTrue($report['domain_errors'] === 1, 'Ein einzelner Zonenfehler wird nicht sicher gezählt.');
    inspectDbsAssertTrue($report['duplicate_domains'] === 1, 'Eine doppelte Origin wird nicht übersprungen.');
    inspectDbsAssertTrue(count($report['examples']['TXT']) === 3, 'Das Beispielmaximum wird nicht eingehalten.');
    inspectDbsAssertTrue(
        $report['examples']['TXT'][0]['data'] === $txtData,
        'Die interne Rohstruktur wird vor der Ausgabe verändert.'
    );

    foreach(dbsInspectorTargetTypes() as $type) {
        inspectDbsAssertTrue(count($report['examples'][$type]) >= 1, $type . ' wurde nicht gesammelt.');
        inspectDbsAssertTrue(count($report['examples'][$type]) <= 3, $type . ' überschreitet das Beispielmaximum.');
        inspectDbsAssertTrue(
            array_keys($report['examples'][$type][0]) === array('name', 'type', 'data', 'aux', 'ttl'),
            $type . ' erhält nicht exakt die fünf Diagnosefelder.'
        );
    }

    $output = dbsInspectorRenderReport($report);
    inspectDbsAssertTrue(
        strpos($output, 'Typ | gefunden? | name-Form | data-Form | aux | ttl | Anzahl Beispiele') !== false,
        'Die kompakte Matrix fehlt.'
    );
    inspectDbsAssertTrue(strpos($output, 'first.example') === false, 'Eine Domain erscheint in der Ausgabe.');
    inspectDbsAssertTrue(strpos($output, 'sensitive') === false, 'Sensible Recorddaten erscheinen in der Ausgabe.');
    inspectDbsAssertTrue(strpos($output, '{mask:') !== false, 'Die anonymisierten Strukturmarker fehlen.');

    $source = file_get_contents(__DIR__ . '/../scripts/inspect-dbs-record-types.php');
    preg_match_all('/\$client->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $methodMatches);
    $calledClientMethods = array_values(array_unique($methodMatches[1]));
    sort($calledClientMethods);
    inspectDbsAssertTrue(
        $calledClientMethods === array('getZoneInfo', 'listAllDomains'),
        'Das Diagnosewerkzeug verwendet andere DbsClient-Methoden als die zwei erlaubten Lesepfade.'
    );

    foreach(array(
        'nameserverRRCreate',
        'nameserverRRDelete',
        'nameserverSOAUpdate',
        'nameserverZoneCreate',
        'nameserverZoneDelete',
        'createRecord',
        'deleteRecord'
    ) as $forbiddenMethod) {
        inspectDbsAssertTrue(
            strpos($source, $forbiddenMethod) === false,
            'Verbotene Write-Methode im Diagnosewerkzeug gefunden: ' . $forbiddenMethod
        );
    }

    $invalidOptionRejected = false;

    try {
        dbsInspectorParseOptions(array('inspect', '--examples=4'));
    } catch (InvalidArgumentException $exception) {
        $invalidOptionRejected = true;
    }

    inspectDbsAssertTrue($invalidOptionRejected, 'Mehr als drei Beispiele werden als Option akzeptiert.');
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'DBS-Recordtyp-Diagnose erfolgreich geprüft.' . PHP_EOL;
