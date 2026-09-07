<?php

require_once __DIR__ . '/../src/dbsdns/lib/classes/DomainMatcher.inc.php';

class DomainMatchingFakeDb
{
    public $queryCount = 0;
    public $lastQuery = '';
    private $resources;

    public function __construct($resources)
    {
        $this->resources = $resources;
    }

    public function queryAllRecords($query)
    {
        $this->queryCount++;
        $this->lastQuery = $query;

        return $this->resources;
    }
}

function domainMatchingAssertSame($expected, $actual, $message)
{
    if($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function domainMatchingAssertTrue($condition, $message)
{
    if(!$condition) {
        throw new RuntimeException($message);
    }
}

function domainMatchingResource($source, $domain, $groupId, $clientId, $parentClientId, $overrides = array())
{
    $resource = array(
        'source' => $source,
        'domain_name' => $domain,
        'sys_groupid' => $groupId,
        'group_client_id' => $clientId,
        'client_id' => $clientId,
        'parent_client_id' => $parentClientId,
        'client_company_name' => 'Client ' . $clientId,
        'client_contact_firstname' => '',
        'client_contact_name' => '',
        'client_username' => 'client' . $clientId,
        'client_customer_no' => '',
        'reseller_client_id' => $parentClientId,
        'reseller_company_name' => $parentClientId > 0 ? 'Reseller ' . $parentClientId : '',
        'reseller_contact_firstname' => '',
        'reseller_contact_name' => '',
        'reseller_username' => $parentClientId > 0 ? 'reseller' . $parentClientId : '',
        'reseller_customer_no' => ''
    );

    return array_merge($resource, $overrides);
}

function domainMatchingDbsDomain($domain)
{
    return array(
        'domain_name' => $domain,
        'origin' => $domain,
        'status' => 'active'
    );
}

$testFailure = null;

try {
    $idnEncoder = function($domain) {
        if($domain === 'bücher.de') {
            return 'xn--bcher-kva.de';
        }

        return $domain;
    };
    $matcher = new DomainMatcher($idnEncoder);

    domainMatchingAssertSame('example.de', $matcher->normalizeDomain('EXAMPLE.DE'), 'Lowercase-Normalisierung ist fehlerhaft.');
    domainMatchingAssertSame('example.de', $matcher->normalizeDomain('example.de.'), 'Der abschließende Punkt wird nicht entfernt.');
    domainMatchingAssertSame('xn--bcher-kva.de', $matcher->normalizeDomain('bücher.de'), 'IDN wird nicht nach ACE normalisiert.');
    domainMatchingAssertSame('xn--bcher-kva.de', $matcher->normalizeDomain('xn--bcher-kva.de'), 'Punycode wird nicht stabil normalisiert.');

    $singleSourceCases = array(
        DomainMatcher::SOURCE_WEB,
        DomainMatcher::SOURCE_MAIL,
        DomainMatcher::SOURCE_DNS
    );

    foreach($singleSourceCases as $source) {
        $matches = $matcher->match(
            array(domainMatchingDbsDomain('single.example')),
            array(domainMatchingResource($source, 'single.example', 501, 12, 0))
        );
        domainMatchingAssertSame(DomainMatcher::STATUS_ASSIGNED, $matches[0]['status'], 'Eine einzelne Quelle wird nicht eindeutig zugeordnet: ' . $source);
        domainMatchingAssertSame(501, $matches[0]['group_id'], 'Die einzelne Quelle führt zur falschen Kundengruppe: ' . $source);
        domainMatchingAssertSame(12, $matches[0]['client_id'], 'Die einzelne Quelle führt zum falschen Kunden: ' . $source);
        domainMatchingAssertSame(array($source), $matches[0]['sources'], 'Die einzelne Match-Quelle fehlt: ' . $source);
    }

    $sameClientMatches = $matcher->match(
        array(domainMatchingDbsDomain('multi.example')),
        array(
            domainMatchingResource(DomainMatcher::SOURCE_WEB, 'multi.example', 501, 12, 0),
            domainMatchingResource(DomainMatcher::SOURCE_MAIL, 'MULTI.EXAMPLE.', 501, 12, 0),
            domainMatchingResource(DomainMatcher::SOURCE_DNS, 'multi.example', 501, 12, 0)
        )
    );
    domainMatchingAssertSame(DomainMatcher::STATUS_ASSIGNED, $sameClientMatches[0]['status'], 'Mehrere Quellen desselben Kunden werden nicht eindeutig zugeordnet.');
    domainMatchingAssertSame(
        array(DomainMatcher::SOURCE_WEB, DomainMatcher::SOURCE_MAIL, DomainMatcher::SOURCE_DNS),
        $sameClientMatches[0]['sources'],
        'Die Quellenreihenfolge ist nicht deterministisch.'
    );

    $conflictMatches = $matcher->match(
        array(domainMatchingDbsDomain('conflict.example')),
        array(
            domainMatchingResource(DomainMatcher::SOURCE_WEB, 'conflict.example', 501, 12, 0),
            domainMatchingResource(DomainMatcher::SOURCE_MAIL, 'conflict.example', 777, 44, 0)
        )
    );
    domainMatchingAssertSame(DomainMatcher::STATUS_CONFLICT, $conflictMatches[0]['status'], 'Unterschiedliche Kunden werden nicht als Konflikt erkannt.');
    domainMatchingAssertSame(0, $conflictMatches[0]['client_id'], 'Ein Konflikt darf keinen Kunden freigeben.');
    domainMatchingAssertSame(2, count($conflictMatches[0]['candidates']), 'Die Konfliktinformationen enthalten nicht alle erkannten Kunden.');

    $unassignedMatches = $matcher->match(
        array(domainMatchingDbsDomain('missing.example')),
        array(domainMatchingResource(DomainMatcher::SOURCE_WEB, 'other.example', 501, 12, 0))
    );
    domainMatchingAssertSame(DomainMatcher::STATUS_UNASSIGNED, $unassignedMatches[0]['status'], 'Eine Domain ohne exakten Treffer wird nicht als unzugeordnet erkannt.');

    $resellerMatches = $matcher->match(
        array(domainMatchingDbsDomain('reseller.example')),
        array(domainMatchingResource(DomainMatcher::SOURCE_WEB, 'reseller.example', 501, 12, 99))
    );
    domainMatchingAssertSame(12, $resellerMatches[0]['client_id'], 'Der Kunde mit Reseller wird falsch aufgelöst.');
    domainMatchingAssertSame(99, $resellerMatches[0]['reseller_id'], 'Der parent_client_id wird nicht als Reseller aufgelöst.');
    domainMatchingAssertTrue(strpos($resellerMatches[0]['reseller_label'], 'Reseller 99') !== false, 'Die Reseller-Anzeige fehlt.');

    $directClientMatches = $matcher->match(
        array(domainMatchingDbsDomain('direct.example')),
        array(domainMatchingResource(DomainMatcher::SOURCE_WEB, 'direct.example', 502, 13, 0))
    );
    domainMatchingAssertSame(0, $directClientMatches[0]['reseller_id'], 'Ein Kunde ohne Reseller erhält eine erfundene Reseller-Zuordnung.');

    $idnMatches = $matcher->match(
        array(domainMatchingDbsDomain('xn--bcher-kva.de')),
        array(domainMatchingResource(DomainMatcher::SOURCE_DNS, 'bücher.de.', 503, 14, 0))
    );
    domainMatchingAssertSame(DomainMatcher::STATUS_ASSIGNED, $idnMatches[0]['status'], 'IDN und Punycode werden nicht exakt zusammengeführt.');

    $fakeDb = new DomainMatchingFakeDb(array(
        domainMatchingResource(DomainMatcher::SOURCE_WEB, 'batch.example', 504, 15, 0)
    ));
    $batchMatches = $matcher->matchDbsDomains($fakeDb, array(domainMatchingDbsDomain('batch.example')));
    domainMatchingAssertSame(1, $fakeDb->queryCount, 'ISPConfig-Ressourcen werden nicht in einer gebündelten Abfrage geladen.');
    domainMatchingAssertTrue(!preg_match('/\b(?:INSERT|UPDATE|DELETE)\b/i', $fakeDb->lastQuery), 'Der Dry-Run enthält eine schreibende Datenbankoperation.');
    domainMatchingAssertTrue(strpos($fakeDb->lastQuery, "FROM `domain`") === false, 'Die gespeicherte Domain-Zuordnung darf nicht als Erkennungsquelle verwendet werden.');
    domainMatchingAssertSame(DomainMatcher::STATUS_ASSIGNED, $batchMatches[0]['status'], 'Der gebündelte Abgleich liefert kein Ergebnis.');
} catch (Throwable $exception) {
    $testFailure = $exception;
}

if($testFailure !== null) {
    fwrite(STDERR, $testFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Domain-Matching-Tests erfolgreich.' . PHP_EOL;
